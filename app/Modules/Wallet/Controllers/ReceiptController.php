<?php

namespace App\Modules\Wallet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wallet\Models\WalletTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Deposit receipt view (Sprint 4), addressed by its ledger transaction uuid.
 * Scoped to the owner — a customer can only ever open their own.
 */
class ReceiptController extends Controller
{
    public function show(Request $request, WalletTransaction $transaction): Response
    {
        if ($transaction->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('This receipt belongs to another account.');
        }

        $receipt = $transaction->receipt;
        if ($receipt === null) {
            throw new NotFoundHttpException('No receipt for this transaction.');
        }

        return Inertia::render('Wallet/Receipt', [
            'receipt' => [
                'receiptNumber' => $receipt->receipt_number,
                'amountKobo' => $receipt->amount_kobo,
                'currency' => $receipt->currency,
                'channel' => $receipt->channel,
                'issuedAt' => $receipt->issued_at->toDayDateTimeString(),
                'customerName' => $request->user()->name,
            ],
        ]);
    }
}
