<?php

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Payments\Models\PaystackWebhookEvent;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Processes a signature-verified Paystack webhook (Sprint 4). Every event is
 * logged first, then a `charge.success` credits the wallet exactly once. Three
 * layers guarantee idempotency: the transaction's webhook_verified_at flag,
 * the wallet ledger's unique reference, and the raw event log. This is the
 * ONLY path that credits a wallet.
 *
 * @param  array<string, mixed>  $payload
 */
class ProcessPaystackWebhook
{
    public function __construct(private readonly WalletService $wallet) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, bool $signatureValid): PaystackWebhookEvent
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;

        $webhookEvent = PaystackWebhookEvent::query()->create([
            'event' => $event,
            'paystack_reference' => $reference,
            'signature_valid' => $signatureValid,
            'payload_hash' => hash('sha256', json_encode($payload) ?: ''),
            'payload' => $payload,
            'processing_status' => 'received',
        ]);

        if (! $signatureValid) {
            return $this->finish($webhookEvent, 'failed', 'Invalid webhook signature.');
        }

        // We only act on successful charges; everything else is logged and skipped.
        if ($event !== 'charge.success' || $reference === null) {
            return $this->finish($webhookEvent, 'ignored', 'Not a processable charge.success event.');
        }

        $transaction = PaystackTransaction::query()->where('paystack_reference', $reference)->first();
        if ($transaction === null) {
            return $this->finish($webhookEvent, 'ignored', 'No matching Paystack transaction.');
        }

        // Idempotency: already verified means the deposit was credited.
        if ($transaction->webhook_verified_at !== null) {
            return $this->finish($webhookEvent, 'ignored', 'Already processed.');
        }

        $paidKobo = (int) ($data['amount'] ?? 0);
        if ($paidKobo <= 0) {
            return $this->finish($webhookEvent, 'failed', 'Missing or invalid charge amount.');
        }

        $channel = is_string($data['channel'] ?? null) ? $data['channel'] : null;
        $user = $transaction->user;

        DB::transaction(function () use ($transaction, $user, $paidKobo, $reference, $channel, $data) {
            $walletTransaction = $this->wallet->creditDeposit(
                user: $user,
                amountKobo: $paidKobo,
                reference: $reference,
                channel: $channel,
                metadata: ['paystack_reference' => $reference],
            );

            $transaction->update([
                'status' => PaystackTransactionStatus::Success,
                'webhook_verified_at' => now(),
                'wallet_transaction_id' => $walletTransaction->id,
                'channel' => $channel,
                'provider_payload' => $data,
            ]);

            $this->captureAuthorization($transaction->user_id, $data);
        });

        return $this->finish($webhookEvent, 'processed', null);
    }

    /**
     * Store reusable card metadata for Phase 2 automatic debit — never charged
     * in Sprint 4, only captured.
     *
     * @param  array<string, mixed>  $data
     */
    private function captureAuthorization(int $userId, array $data): void
    {
        $auth = is_array($data['authorization'] ?? null) ? $data['authorization'] : null;
        $code = is_string($auth['authorization_code'] ?? null) ? $auth['authorization_code'] : null;

        if ($auth === null || $code === null || ($auth['reusable'] ?? false) !== true) {
            return;
        }

        PaymentAuthorization::query()->updateOrCreate(
            ['user_id' => $userId, 'authorization_code' => $code],
            [
                'signature' => $auth['signature'] ?? null,
                'card_type' => $auth['card_type'] ?? null,
                'bank' => $auth['bank'] ?? null,
                'last4' => $auth['last4'] ?? null,
                'exp_month' => $auth['exp_month'] ?? null,
                'exp_year' => $auth['exp_year'] ?? null,
                'reusable' => true,
                'active' => true,
            ],
        );
    }

    private function finish(PaystackWebhookEvent $event, string $status, ?string $error): PaystackWebhookEvent
    {
        $event->update([
            'processing_status' => $status,
            'error_message' => $error,
            'processed_at' => now(),
        ]);

        return $event;
    }
}
