<?php

namespace App\Modules\Affiliates\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateBankAccount;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliatePayoutBatch;
use App\Modules\Affiliates\Models\AffiliatePayoutItem;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\PayoutBatchStatus;
use App\Shared\Enums\PayoutItemStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly partner payouts.
 *
 * Four gates stand between a commission and a transfer, and each one exists
 * because of a specific way money could otherwise leave wrongly:
 *
 *  - **Only qualified conversions count.** A conversion still in review, or
 *    rejected outright, is never gathered — that is what the review queue is
 *    for.
 *  - **A minimum threshold.** Transfers cost money; paying ₦40 costs more in
 *    fees than it settles. Below the threshold the commissions stay pending
 *    and roll into next month rather than being lost.
 *  - **A verified bank account.** No verified destination, no payout line.
 *  - **Finance approval.** Generating a batch proposes; approving commits.
 *
 * Affiliate money is entirely separate from customer plan money. Nothing in
 * this class reads or writes a savings ledger, and it cannot: commissions
 * are their own table, funded by the business, not by anybody's plan.
 */
class AffiliatePayoutService
{
    public const DEFAULT_MINIMUM_KOBO = 500_000; // ₦5,000

    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    public function minimumThresholdKobo(): int
    {
        return max(0, (int) Setting::get('affiliates.payout_minimum_kobo', self::DEFAULT_MINIMUM_KOBO));
    }

    /**
     * Gather every payable partner into a draft batch awaiting Finance.
     *
     * Commissions are moved to `batched` and pointed at their payout item, so
     * a second generate in the same month cannot gather them twice.
     */
    public function generateBatch(User $financeUser, ?string $periodStart = null, ?string $periodEnd = null): AffiliatePayoutBatch
    {
        $threshold = $this->minimumThresholdKobo();

        return DB::transaction(function () use ($financeUser, $periodStart, $periodEnd, $threshold) {
            $batch = AffiliatePayoutBatch::query()->create([
                'period_start' => $periodStart ?? now()->subMonth()->toDateString(),
                'period_end' => $periodEnd ?? now()->toDateString(),
                'status' => PayoutBatchStatus::PendingApproval,
                'total_amount_kobo' => 0,
                'minimum_threshold_kobo' => $threshold,
                'generated_by' => $financeUser->id,
            ]);

            $total = 0;

            $affiliates = Affiliate::query()
                ->where('status', Affiliate::STATUS_APPROVED)
                ->whereNull('suspended_at')
                ->get();

            foreach ($affiliates as $affiliate) {
                $commissions = $this->payableCommissions($affiliate)->get();
                $amount = (int) $commissions->sum('amount_kobo');

                if ($amount < $threshold || $amount <= 0) {
                    continue;
                }

                $account = AffiliateBankAccount::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->where('is_active', true)
                    ->whereNotNull('verified_at')
                    ->first();

                if ($account === null) {
                    // No verified destination — the commissions stay pending
                    // and will be picked up by a later batch once verified.
                    continue;
                }

                $item = AffiliatePayoutItem::query()->create([
                    'batch_id' => $batch->id,
                    'affiliate_id' => $affiliate->id,
                    'bank_account_id' => $account->id,
                    'amount_kobo' => $amount,
                    'status' => PayoutItemStatus::Pending,
                ]);

                AffiliateCommission::query()
                    ->whereIn('id', $commissions->pluck('id'))
                    ->update([
                        'status' => AffiliateCommission::STATUS_BATCHED,
                        'payout_item_id' => $item->id,
                    ]);

                $total += $amount;
            }

            $batch->forceFill(['total_amount_kobo' => $total])->save();

            $this->auditLogger->log(
                actor: $financeUser,
                subject: $batch,
                action: 'affiliate.payout_batch_generated',
                newValues: ['total_amount_kobo' => $total, 'items' => $batch->items()->count(), 'threshold_kobo' => $threshold],
            );

            return $batch;
        });
    }

    public function approveBatch(User $financeUser, AffiliatePayoutBatch $batch): AffiliatePayoutBatch
    {
        if ($batch->status !== PayoutBatchStatus::PendingApproval) {
            throw ValidationException::withMessages(['batch' => 'Only a pending batch can be approved.']);
        }

        return DB::transaction(function () use ($financeUser, $batch) {
            $batch->forceFill([
                'status' => PayoutBatchStatus::Approved,
                'approved_by' => $financeUser->id,
                'approved_at' => now(),
            ])->save();

            $batch->items()->where('status', PayoutItemStatus::Pending)->update(['status' => PayoutItemStatus::Approved]);

            $this->auditLogger->log(actor: $financeUser, subject: $batch, action: 'affiliate.payout_batch_approved');

            return $batch;
        });
    }

    /**
     * Refuse one partner's line with a stated reason. Their commissions go
     * back to pending rather than being destroyed — the work still happened,
     * and a rejection is usually about this batch (wrong account, disputed
     * conversion), not about voiding the earning.
     */
    public function rejectItem(User $financeUser, AffiliatePayoutItem $item, string $reason): AffiliatePayoutItem
    {
        if (in_array($item->status, [PayoutItemStatus::Paid, PayoutItemStatus::Rejected], true)) {
            throw ValidationException::withMessages(['item' => 'That payout line has already been settled.']);
        }

        return DB::transaction(function () use ($financeUser, $item, $reason) {
            $item->forceFill([
                'status' => PayoutItemStatus::Rejected,
                'rejection_reason' => $reason,
            ])->save();

            $item->commissions()->update([
                'status' => AffiliateCommission::STATUS_PENDING,
                'payout_item_id' => null,
            ]);

            $this->auditLogger->log(
                actor: $financeUser,
                subject: $item,
                action: 'affiliate.payout_item_rejected',
                newValues: ['reason' => $reason, 'amount_kobo' => $item->amount_kobo],
            );

            $this->refreshBatchStatus($item->batch);

            return $item;
        });
    }

    public function markItemPaid(User $financeUser, AffiliatePayoutItem $item, string $transferReference): AffiliatePayoutItem
    {
        if ($item->status !== PayoutItemStatus::Approved) {
            throw ValidationException::withMessages(['item' => 'Only an approved payout line can be marked paid.']);
        }

        return DB::transaction(function () use ($financeUser, $item, $transferReference) {
            $item->forceFill([
                'status' => PayoutItemStatus::Paid,
                'paystack_transfer_reference' => $transferReference,
                'paid_at' => now(),
            ])->save();

            $item->commissions()->update(['status' => AffiliateCommission::STATUS_PAID]);

            $this->auditLogger->log(
                actor: $financeUser,
                subject: $item,
                action: 'affiliate.payout_item_paid',
                newValues: ['amount_kobo' => $item->amount_kobo, 'reference' => $transferReference],
            );

            $this->refreshBatchStatus($item->batch);

            return $item;
        });
    }

    /** A failed transfer returns the commissions to pending so a retry is possible. */
    public function markItemFailed(User $financeUser, AffiliatePayoutItem $item, string $reason): AffiliatePayoutItem
    {
        if (! in_array($item->status, [PayoutItemStatus::Approved, PayoutItemStatus::Pending], true)) {
            throw ValidationException::withMessages(['item' => 'Only a pending or approved payout line can fail.']);
        }

        return DB::transaction(function () use ($financeUser, $item, $reason) {
            $item->forceFill(['status' => PayoutItemStatus::Failed, 'failure_reason' => $reason])->save();

            $item->commissions()->update([
                'status' => AffiliateCommission::STATUS_PENDING,
                'payout_item_id' => null,
            ]);

            $this->auditLogger->log(
                actor: $financeUser,
                subject: $item,
                action: 'affiliate.payout_item_failed',
                newValues: ['reason' => $reason],
            );

            $this->refreshBatchStatus($item->batch);

            return $item;
        });
    }

    // ── Bank accounts ───────────────────────────────────────────────────────

    public function addBankAccount(Affiliate $affiliate, array $data): AffiliateBankAccount
    {
        return DB::transaction(function () use ($affiliate, $data) {
            // One active destination at a time: several verified accounts
            // would make "which one gets paid" arbitrary.
            $affiliate->bankAccounts()->update(['is_active' => false]);

            return $affiliate->bankAccounts()->create([
                'bank_name' => $data['bank_name'],
                'bank_code' => $data['bank_code'] ?? null,
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'],
                'is_active' => true,
                // Changing the destination always re-enters verification.
                'verified_at' => null,
                'verified_by' => null,
            ]);
        });
    }

    public function verifyBankAccount(User $staff, AffiliateBankAccount $account): AffiliateBankAccount
    {
        $account->forceFill(['verified_at' => now(), 'verified_by' => $staff->id])->save();

        $this->auditLogger->log(
            actor: $staff,
            subject: $account,
            action: 'affiliate.bank_account_verified',
            newValues: ['affiliate_id' => $account->affiliate_id],
        );

        return $account;
    }

    /**
     * What an affiliate could be paid today: qualified conversions only,
     * never anything in review or rejected.
     *
     * @return \Illuminate\Database\Eloquent\Builder<AffiliateCommission>
     */
    public function payableCommissions(Affiliate $affiliate)
    {
        return AffiliateCommission::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('status', AffiliateCommission::STATUS_PENDING)
            ->whereHas('conversion', fn ($query) => $query->where('status', \App\Modules\Affiliates\Models\AffiliateConversion::STATUS_QUALIFIED));
    }

    private function refreshBatchStatus(AffiliatePayoutBatch $batch): void
    {
        $open = $batch->items()
            ->whereIn('status', [PayoutItemStatus::Pending, PayoutItemStatus::Approved])
            ->exists();

        if ($open) {
            $batch->forceFill(['status' => PayoutBatchStatus::Processing])->save();

            return;
        }

        $anyFailed = $batch->items()->where('status', PayoutItemStatus::Failed)->exists();

        $batch->forceFill([
            'status' => $anyFailed ? PayoutBatchStatus::Failed : PayoutBatchStatus::Completed,
        ])->save();
    }
}
