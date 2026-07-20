<?php

namespace App\Modules\Vendor\Services;

use App\Models\User;
use App\Modules\Vendor\Models\VendorBankAccount;
use App\Modules\Vendor\Models\VendorEarning;
use App\Modules\Vendor\Models\VendorPayoutBatch;
use App\Modules\Vendor\Models\VendorPayoutItem;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\PayoutBatchStatus;
use App\Shared\Enums\PayoutItemStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Weekly vendor payout batches (docs/firstmarket_Implementation_Plan.md
 * Sprint 6 step 9): Finance generates a batch of every positive cleared
 * earnings balance with a verified active bank account, approves it, then
 * marks each transfer paid or failed. The negative ledger row is written
 * only on paid — a failed transfer leaves the ledger untouched so a retry
 * can never double-debit. Vendor payouts never touch customer wallets.
 */
class PayoutService
{
    public function __construct(
        private readonly EarningsService $earningsService,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /** Generate a draft batch of all payable vendor balances. */
    public function generateBatch(User $financeUser, ?string $periodStart = null, ?string $periodEnd = null): VendorPayoutBatch
    {
        return DB::transaction(function () use ($financeUser, $periodStart, $periodEnd) {
            $batch = VendorPayoutBatch::query()->create([
                'period_start' => $periodStart ?? now()->subWeek()->toDateString(),
                'period_end' => $periodEnd ?? now()->toDateString(),
                'status' => PayoutBatchStatus::PendingApproval,
                'total_amount_kobo' => 0,
                'generated_by' => $financeUser->id,
            ]);

            $total = 0;

            // Every vendor with a positive ledger balance and a verified
            // active bank account gets one item for the full cleared balance.
            $vendorIds = VendorEarning::query()->distinct()->pluck('vendor_id');

            foreach ($vendorIds as $vendorId) {
                $balance = (int) VendorEarning::query()
                    ->where('vendor_id', $vendorId)
                    ->orderByDesc('id')
                    ->value('balance_after_kobo');

                if ($balance <= 0) {
                    continue;
                }

                $account = VendorBankAccount::query()
                    ->where('vendor_id', $vendorId)
                    ->where('is_active', true)
                    ->whereNotNull('verified_at')
                    ->first();

                if ($account === null) {
                    continue; // No verified payout destination — skip, balance stays.
                }

                VendorPayoutItem::query()->create([
                    'batch_id' => $batch->id,
                    'vendor_id' => $vendorId,
                    'bank_account_id' => $account->id,
                    'amount_kobo' => $balance,
                    'status' => PayoutItemStatus::Pending,
                ]);

                $total += $balance;
            }

            $batch->forceFill(['total_amount_kobo' => $total])->save();

            $this->auditLogger->log(actor: $financeUser, subject: $batch, action: 'vendor.payout_batch_generated', newValues: [
                'total_amount_kobo' => $total,
                'items' => $batch->items()->count(),
            ]);

            return $batch;
        });
    }

    /** Finance approval: batch and all pending items become approved. */
    public function approveBatch(User $financeUser, VendorPayoutBatch $batch): VendorPayoutBatch
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

            $this->auditLogger->log(actor: $financeUser, subject: $batch, action: 'vendor.payout_batch_approved', newValues: []);

            return $batch;
        });
    }

    /**
     * Mark a transfer paid: writes the negative ledger row (exactly here,
     * nowhere else) and stamps the provider reference.
     */
    public function markItemPaid(User $financeUser, VendorPayoutItem $item, string $transferReference): VendorPayoutItem
    {
        if ($item->status !== PayoutItemStatus::Approved) {
            throw ValidationException::withMessages(['item' => 'Only an approved payout item can be marked paid.']);
        }

        return DB::transaction(function () use ($financeUser, $item, $transferReference) {
            $this->earningsService->debitPayout(
                vendorId: $item->vendor_id,
                payoutItemId: $item->id,
                amountKobo: $item->amount_kobo,
                note: "Payout batch {$item->batch->uuid}",
            );

            $item->forceFill([
                'status' => PayoutItemStatus::Paid,
                'paystack_transfer_reference' => $transferReference,
                'paid_at' => now(),
            ])->save();

            $this->auditLogger->log(actor: $financeUser, subject: $item, action: 'vendor.payout_item_paid', newValues: [
                'amount_kobo' => $item->amount_kobo,
                'reference' => $transferReference,
            ]);

            $this->refreshBatchStatus($item->batch);

            return $item;
        });
    }

    /** Mark a transfer failed — the ledger stays intact for a later retry. */
    public function markItemFailed(User $financeUser, VendorPayoutItem $item, string $reason): VendorPayoutItem
    {
        if (! in_array($item->status, [PayoutItemStatus::Approved, PayoutItemStatus::Pending], true)) {
            throw ValidationException::withMessages(['item' => 'Only a pending/approved payout item can fail.']);
        }

        $item->forceFill([
            'status' => PayoutItemStatus::Failed,
            'failure_reason' => $reason,
        ])->save();

        $this->auditLogger->log(actor: $financeUser, subject: $item, action: 'vendor.payout_item_failed', newValues: [
            'reason' => $reason,
        ]);

        $this->refreshBatchStatus($item->batch);

        return $item;
    }

    /** Batch completes when no items remain pending/approved. */
    private function refreshBatchStatus(VendorPayoutBatch $batch): void
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
