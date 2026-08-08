<?php

namespace App\Modules\Payments\Actions;

use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Payments\Models\PaystackWebhookEvent;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Processes a signature-verified Paystack webhook. Every event is logged
 * first, then a `charge.success` is applied exactly once.
 *
 * A charge now pays for one of two things, and which was decided when it was
 * initialized — never read back out of the callback, so a tampered request
 * cannot redirect someone else's money:
 *
 *   order            → complete the pending checkout session, raise orders
 *   plan_installment → credit that Pay Small Small plan
 *
 * Three layers guarantee idempotency: the transaction's webhook_verified_at
 * flag, the unique reference on the thing being credited, and the raw event
 * log. This is the ONLY path that moves money into the system.
 */
class ProcessPaystackWebhook
{
    public function __construct(
        private readonly SavingsGoalService $plans,
        private readonly CartCheckoutService $checkout,
    ) {}

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

        // The event name says charge.success, but the payload carries its own
        // status. Trust the narrower of the two.
        $chargeStatus = is_string($data['status'] ?? null) ? $data['status'] : null;
        if ($chargeStatus !== null && $chargeStatus !== 'success') {
            return $this->finish($webhookEvent, 'ignored', 'Charge did not succeed: '.$chargeStatus);
        }

        // `amount` is in the charge's own minor unit. Banking a USD figure as
        // kobo would credit ~1500x what was paid, so refuse anything that is
        // not the currency we charge in rather than assuming.
        $currency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : 'NGN';
        if ($currency !== 'NGN') {
            return $this->finish($webhookEvent, 'failed', 'Unexpected charge currency: '.$currency);
        }

        $paidKobo = (int) ($data['amount'] ?? 0);
        if ($paidKobo <= 0) {
            return $this->finish($webhookEvent, 'failed', 'Missing or invalid charge amount.');
        }

        // Defence in depth: the signature already proves Paystack sent this,
        // so a mismatch is a bug or a configuration drift rather than an
        // attack — but crediting more than was ever requested is the one
        // error that must never pass silently.
        if ($paidKobo > $transaction->amount_kobo) {
            return $this->finish(
                $webhookEvent,
                'failed',
                'Charge of '.$paidKobo.' exceeds the '.$transaction->amount_kobo.' requested.',
            );
        }

        if ($transaction->purpose === 'shipment_goods') {
            if ($paidKobo !== $transaction->amount_kobo) {
                return $this->finish(
                    $webhookEvent,
                    'failed',
                    'Shipment goods payment must match the requested amount exactly.',
                );
            }

            /*
             * Checked out here rather than left to settleShipment().
             *
             * That method throws, and it runs inside the DB transaction below
             * — a throw becomes a 500, which Paystack answers by retrying the
             * same webhook indefinitely while the shopper's money has already
             * moved. Recording the event as failed leaves the same evidence
             * without the retry storm.
             */
            $shipment = $transaction->shipment;

            if ($shipment === null) {
                return $this->finish($webhookEvent, 'failed', 'Payment is not attached to a shipment.');
            }

            if ($paidKobo !== $shipment->collect_on_delivery_kobo) {
                return $this->finish(
                    $webhookEvent,
                    'failed',
                    'Payment of '.$paidKobo.' no longer matches the '
                        .$shipment->collect_on_delivery_kobo.' owed on this shipment.',
                );
            }
        }

        $channel = is_string($data['channel'] ?? null) ? $data['channel'] : null;
        $user = $transaction->user;

        DB::transaction(function () use ($transaction, $user, $paidKobo, $reference, $channel, $data) {
            match ($transaction->purpose) {
                'plan_installment' => $this->creditPlan($transaction, $user, $paidKobo, $reference),
                'shipment_goods' => $this->settleShipment($transaction, $paidKobo),
                default => $this->completeOrder($transaction),
            };

            $transaction->update([
                'status' => PaystackTransactionStatus::Success,
                'webhook_verified_at' => now(),
                'channel' => $channel,
                'provider_payload' => $data,
            ]);

            $this->captureAuthorization($transaction->user_id, $data);
        });

        return $this->finish($webhookEvent, 'processed', null);
    }

    /** An instalment landing on a Pay Small Small plan. */
    private function creditPlan(PaystackTransaction $transaction, $user, int $paidKobo, string $reference): void
    {
        $goal = $transaction->savingsGoal;

        if ($goal === null) {
            return;
        }

        $this->plans->recordPayment($user, $goal, $paidKobo, source: 'card', reference: $reference);
    }

    /** A card checkout clearing: turn the frozen session into orders. */
    private function completeOrder(PaystackTransaction $transaction): void
    {
        $session = $transaction->checkoutSession;

        if ($session === null) {
            return;
        }

        $this->checkout->completePaidSession($session);
    }

    /** Mark the shipment balance paid only after Paystack has verified it. */
    private function settleShipment(PaystackTransaction $transaction, int $paidKobo): void
    {
        $shipment = $transaction->shipment;

        if ($shipment === null || $paidKobo !== $shipment->collect_on_delivery_kobo) {
            throw new \RuntimeException('Shipment payment does not match its goods balance.');
        }

        if ($shipment->goods_paid_at === null) {
            $shipment->forceFill([
                'goods_paid_at' => now(),
                'goods_paid_by' => $transaction->user_id,
            ])->save();

            $shipment->orders()
                ->whereNull('goods_paid_at')
                ->whereNotIn('status', ['cancelled', 'vendor_rejected'])
                ->update(['goods_paid_at' => $shipment->goods_paid_at]);
        }
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
