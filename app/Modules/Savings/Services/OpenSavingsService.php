<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Modules\Savings\Models\OpenSaving;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\WalletStatus;
use App\Shared\Enums\WalletTransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns the Open Savings pot (docs/FirstMaket_Implementation_Plan.md Sprint
 * 5): exactly one per customer, funded only from the wallet through a ledger
 * debit, spendable only into Product Target Plans. There is no withdrawal —
 * money that enters savings can only ever move toward a product.
 */
class OpenSavingsService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /** Every customer has exactly one Open Savings pot; create it lazily. */
    public function getOrCreate(User $user): OpenSaving
    {
        $wallet = $this->walletService->getOrCreate($user);

        return OpenSaving::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['wallet_id' => $wallet->id, 'balance_kobo' => 0, 'status' => WalletStatus::Active],
        );
    }

    /**
     * Move money from the wallet into Open Savings. The wallet debit (ledger
     * row) and the pot credit commit in one transaction; the wallet row lock
     * inside debitForSavings serializes concurrent allocations.
     */
    public function allocateFromWallet(User $user, int $amountKobo): OpenSaving
    {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($user, $amountKobo) {
            $openSaving = OpenSaving::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first() ?? $this->getOrCreate($user);

            if ($openSaving->status !== WalletStatus::Active) {
                throw ValidationException::withMessages(['amount' => 'Open Savings is not active.']);
            }

            $transaction = $this->walletService->debitForSavings(
                user: $user,
                amountKobo: $amountKobo,
                type: WalletTransactionType::OpenSavingsAllocation,
                reference: 'OSALLOC-'.Str::uuid()->toString(),
                metadata: ['open_saving_id' => $openSaving->id],
            );

            $openSaving->forceFill(['balance_kobo' => $openSaving->balance_kobo + $amountKobo])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $openSaving,
                action: 'savings.open_allocation',
                newValues: [
                    'amount_kobo' => $amountKobo,
                    'balance_kobo' => $openSaving->balance_kobo,
                    'wallet_transaction_reference' => $transaction->reference,
                ],
            );

            return $openSaving;
        });
    }
}
