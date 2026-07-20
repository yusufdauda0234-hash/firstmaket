<?php

namespace App\Modules\Wallet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer wallet overview (Sprint 4): balance and recent ledger activity.
 * The wallet is deposit-only — there is no withdraw action here or anywhere.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallet) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $wallet = $this->wallet->getOrCreate($user);

        $recent = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->latest('id')
            ->limit(6)
            ->get()
            ->map($this->mapTransaction(...));

        return Inertia::render('Wallet/Index', [
            'wallet' => [
                'balanceKobo' => $wallet->balance_kobo,
                'currency' => $wallet->currency,
                'status' => $wallet->status->value,
            ],
            'recentTransactions' => $recent,
            'phoneVerified' => $user->hasVerifiedPhone(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTransaction(WalletTransaction $transaction): array
    {
        return [
            'uuid' => $transaction->uuid,
            'type' => $transaction->type->value,
            'direction' => $transaction->direction->value,
            'amountKobo' => $transaction->amount_kobo,
            'balanceAfterKobo' => $transaction->balance_after_kobo,
            'reference' => $transaction->reference,
            'receiptNumber' => $transaction->receipt_number,
            'createdAt' => $transaction->created_at->toDayDateTimeString(),
        ];
    }
}
