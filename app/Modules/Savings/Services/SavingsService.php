<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Modules\Savings\Models\Savings;
use App\Modules\Savings\Models\SavingsTransaction;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\LedgerDirection;
use App\Shared\Enums\SavingsStatus;
use App\Shared\Enums\SavingsTransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan credit — the only money FirstMaket holds on a customer's behalf.
 *
 * There is no wallet and no way to deposit. Credit can be created by exactly
 * two events, both of which are money the customer already paid coming back
 * to them: cancelling a Pay Small Small plan, and a refund after a vendor
 * rejects an order. It can be spent on exactly one thing: another plan.
 *
 * That asymmetry is the point. Nothing here can pay out cash, so no call
 * shaped like a withdrawal can compile — and FirstMaket stays a marketplace
 * rather than something a regulator would call a deposit-taking institution.
 *
 * Every change is row-locked and appends a ledger row with
 * balance_before/after, so the credit figure is always reconstructable.
 */
class SavingsService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    /** Every customer has exactly one credit record; create it lazily. */
    public function getOrCreate(User $user): Savings
    {
        return Savings::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => 'NGN', 'balance_kobo' => 0, 'credit_kobo' => 0, 'status' => SavingsStatus::Active],
        );
    }

    /** Credit available to put toward a plan. */
    public function creditKobo(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return (int) Savings::query()->where('user_id', $user->id)->value('credit_kobo');
    }

    /** Money paid into a plan the customer walked away from. */
    public function creditFromCancelledPlan(User $user, int $amountKobo, string $goalUuid): SavingsTransaction
    {
        return $this->credit(
            $user,
            $amountKobo,
            SavingsTransactionType::Refund,
            'PLAN-CANCEL-'.$goalUuid,
            ['savings_goal_uuid' => $goalUuid],
            'savings.credit_from_cancelled_plan',
        );
    }

    /** Money back after a vendor rejects an order. */
    public function creditRefund(User $user, int $amountKobo, string $reference, array $metadata = []): SavingsTransaction
    {
        return $this->credit(
            $user,
            $amountKobo,
            SavingsTransactionType::Refund,
            $reference,
            $metadata,
            'savings.refund_credited',
        );
    }

    /**
     * Apply credit to a plan. The plan records the payment itself; this only
     * draws the credit down, in the same transaction as the caller.
     */
    public function consumeCredit(User $user, int $amountKobo, string $goalUuid): SavingsTransaction
    {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($user, $amountKobo, $goalUuid) {
            $savings = Savings::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? $this->getOrCreate($user);

            if ($savings->credit_kobo < $amountKobo) {
                throw ValidationException::withMessages(['amount' => 'Not enough plan credit.']);
            }

            $before = $savings->credit_kobo;
            $after = $before - $amountKobo;

            $transaction = SavingsTransaction::query()->create([
                'savings_id' => $savings->id,
                'user_id' => $user->id,
                'type' => SavingsTransactionType::GoalFulfilment,
                'direction' => LedgerDirection::Debit,
                'amount_kobo' => $amountKobo,
                'balance_before_kobo' => $before,
                'balance_after_kobo' => $after,
                'reference' => 'CREDIT-USE-'.$goalUuid.'-'.$savings->id,
                'metadata' => ['savings_goal_uuid' => $goalUuid],
            ]);

            $savings->forceFill(['credit_kobo' => $after])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $transaction,
                action: 'savings.credit_applied_to_plan',
                newValues: ['amount_kobo' => $amountKobo, 'credit_after_kobo' => $after],
            );

            return $transaction;
        });
    }

    /**
     * Shared credit path. Idempotent by reference, so a replayed refund or a
     * double-clicked cancel cannot credit twice.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function credit(
        User $user,
        int $amountKobo,
        SavingsTransactionType $type,
        string $reference,
        array $metadata,
        string $auditAction,
    ): SavingsTransaction {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($user, $amountKobo, $type, $reference, $metadata, $auditAction) {
            $existing = SavingsTransaction::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                return $existing;
            }

            $savings = Savings::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? $this->getOrCreate($user);

            $before = $savings->credit_kobo;
            $after = $before + $amountKobo;

            $transaction = SavingsTransaction::query()->create([
                'savings_id' => $savings->id,
                'user_id' => $user->id,
                'type' => $type,
                'direction' => LedgerDirection::Credit,
                'amount_kobo' => $amountKobo,
                'balance_before_kobo' => $before,
                'balance_after_kobo' => $after,
                'reference' => $reference,
                'metadata' => $metadata,
            ]);

            $savings->forceFill(['credit_kobo' => $after])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $transaction,
                action: $auditAction,
                newValues: ['amount_kobo' => $amountKobo, 'credit_after_kobo' => $after],
            );

            return $transaction;
        });
    }
}
