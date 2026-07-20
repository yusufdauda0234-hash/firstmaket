<?php

namespace App\Modules\Wallet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Shared\Enums\WalletTransactionType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Full wallet transaction history with type and date filters (Sprint 4).
 * Scoped to the authenticated user's own ledger only.
 */
class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $type = WalletTransactionType::tryFrom((string) $request->query('type'));
        $from = $request->date('from');
        $to = $request->date('to');

        $transactions = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (WalletTransaction $transaction) => [
                'uuid' => $transaction->uuid,
                'type' => $transaction->type->value,
                'direction' => $transaction->direction->value,
                'amountKobo' => $transaction->amount_kobo,
                'balanceAfterKobo' => $transaction->balance_after_kobo,
                'reference' => $transaction->reference,
                'receiptNumber' => $transaction->receipt_number,
                'createdAt' => $transaction->created_at->toDayDateTimeString(),
            ]);

        return Inertia::render('Wallet/Transactions', [
            'transactions' => $transactions,
            'filters' => [
                'type' => $type?->value,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ]);
    }
}
