<?php

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where Paystack returns the browser after checkout (Sprint 4). This screen
 * only *reports* status — it never credits the wallet (that is webhook-only).
 * The webhook may still be in flight, so an as-yet-unconfirmed charge shows
 * as "pending", not failed.
 */
class PaymentCallbackController extends Controller
{
    public function show(Request $request): Response
    {
        $reference = (string) ($request->query('reference') ?? $request->query('trxref') ?? '');

        $transaction = PaystackTransaction::query()
            ->where('paystack_reference', $reference)
            ->where('user_id', $request->user()->id)
            ->first();

        $state = match (true) {
            $transaction === null => 'unknown',
            $transaction->status === PaystackTransactionStatus::Success => 'success',
            $transaction->status === PaystackTransactionStatus::Failed => 'failed',
            default => 'pending', // webhook not in yet — the common case right after redirect
        };

        return Inertia::render('Wallet/PaymentCallback', [
            'state' => $state,
            'amountKobo' => $transaction?->amount_kobo,
            'reference' => $reference,
        ]);
    }
}
