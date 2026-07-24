<?php

namespace App\Modules\Wallet\Services;

use App\Models\User;
use App\Modules\Wallet\Models\Receipt;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\LedgerDirection;
use App\Shared\Enums\WalletStatus;
use App\Shared\Enums\WalletTransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single gateway for every wallet balance change
 * (docs/FirstMaket_Implementation_Plan.md Sprint 4). Balances are only ever
 * mutated here, inside a database transaction that row-locks the wallet, so
 * concurrent deposits serialize and balance_before/after stay exact. The
 * ledger is append-only and each credit issues its receipt in the same
 * transaction.
 *
 * Sprint 5 adds internal debits that move wallet money into the savings
 * engine (plan contributions, Open Savings allocations). Those are the only
 * debit types allowed — there is no debit-to-external / withdrawal method.
 */
class WalletService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    /** Every customer has exactly one wallet; create it lazily. */
    public function getOrCreate(User $user): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => 'NGN', 'balance_kobo' => 0, 'status' => WalletStatus::Active],
        );
    }

    /**
     * Credit a verified Paystack deposit to the wallet. Idempotent by
     * `$reference` (the Paystack reference): calling twice with the same
     * reference returns the existing ledger row and never double-credits.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function creditDeposit(
        User $user,
        int $amountKobo,
        string $reference,
        ?string $channel = null,
        array $metadata = [],
    ): WalletTransaction {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Deposit amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($user, $amountKobo, $reference, $channel, $metadata) {
            // Idempotency: a ledger row for this reference already means the
            // deposit was credited (e.g. a duplicate/replayed webhook).
            $existing = WalletTransaction::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                return $existing;
            }

            // Lock the wallet row so concurrent deposits can't read a stale
            // balance and clobber each other's balance_after.
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first()
                ?? Wallet::query()->create([
                    'user_id' => $user->id,
                    'currency' => 'NGN',
                    'balance_kobo' => 0,
                    'status' => WalletStatus::Active,
                ]);

            if ($wallet->status !== WalletStatus::Active) {
                throw ValidationException::withMessages(['wallet' => 'This wallet is not active.']);
            }

            $balanceBefore = $wallet->balance_kobo;
            $balanceAfter = $balanceBefore + $amountKobo;

            $transaction = WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Deposit,
                'direction' => LedgerDirection::Credit,
                'amount_kobo' => $amountKobo,
                'balance_before_kobo' => $balanceBefore,
                'balance_after_kobo' => $balanceAfter,
                'reference' => $reference,
                'metadata' => $metadata,
            ]);

            // Receipt number derived from the ledger id — unique and readable.
            $receiptNumber = 'FM-'.now()->format('Ymd').'-'.str_pad((string) $transaction->id, 6, '0', STR_PAD_LEFT);
            $transaction->forceFill(['receipt_number' => $receiptNumber])->save();

            Receipt::query()->create([
                'wallet_transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'receipt_number' => $receiptNumber,
                'amount_kobo' => $amountKobo,
                'currency' => $wallet->currency,
                'channel' => $channel,
                'issued_at' => now(),
            ]);

            $wallet->forceFill(['balance_kobo' => $balanceAfter])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $transaction,
                action: 'wallet.deposit_credited',
                newValues: [
                    'amount_kobo' => $amountKobo,
                    'balance_after_kobo' => $balanceAfter,
                    'reference' => $reference,
                ],
            );

            return $transaction;
        });
    }

    /**
     * Debit the wallet for an internal savings/purchase move (plan
     * contribution, Open Savings allocation, or a cart pay-in-full checkout).
     * Never external: only these internal types are accepted, enforced here
     * so no withdrawal-shaped call can ever compile into a balance decrease.
     * Locks the wallet row, checks funds, and appends the ledger row with
     * exact balance_before/after.
     *
     * Callers own the surrounding DB transaction so the ledger row and the
     * savings-side credit commit atomically.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function debitForSavings(
        User $user,
        int $amountKobo,
        WalletTransactionType $type,
        string $reference,
        array $metadata = [],
    ): WalletTransaction {
        if (! in_array($type, [
            WalletTransactionType::PlanContribution,
            WalletTransactionType::OpenSavingsAllocation,
            WalletTransactionType::CartCheckout,
        ], true)) {
            throw new \InvalidArgumentException('Only internal savings debits are allowed on the wallet.');
        }

        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($user, $amountKobo, $type, $reference, $metadata) {
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($wallet === null || $wallet->status !== WalletStatus::Active) {
                throw ValidationException::withMessages(['wallet' => 'This wallet is not active.']);
            }

            if ($wallet->balance_kobo < $amountKobo) {
                throw ValidationException::withMessages([
                    'amount' => 'Insufficient wallet balance. Add money to your wallet first.',
                ]);
            }

            $balanceBefore = $wallet->balance_kobo;
            $balanceAfter = $balanceBefore - $amountKobo;

            $transaction = WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => $type,
                'direction' => LedgerDirection::Debit,
                'amount_kobo' => $amountKobo,
                'balance_before_kobo' => $balanceBefore,
                'balance_after_kobo' => $balanceAfter,
                'reference' => $reference,
                'metadata' => $metadata,
            ]);

            $wallet->forceFill(['balance_kobo' => $balanceAfter])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $transaction,
                action: 'wallet.savings_debit',
                newValues: [
                    'type' => $type->value,
                    'amount_kobo' => $amountKobo,
                    'balance_after_kobo' => $balanceAfter,
                    'reference' => $reference,
                ],
            );

            return $transaction;
        });
    }
}
