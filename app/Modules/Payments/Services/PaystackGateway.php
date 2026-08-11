<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Shared\Contracts\ChargeAttempt;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\PaymentInitialization;
use App\Shared\Contracts\TransactionSnapshot;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paystack payment driver (https://paystack.com/docs/api/transaction/).
 * Initializes hosted charges and verifies webhook signatures. Savings is
 * never credited here — that happens only after ProcessPaystackWebhook
 * confirms a signature-verified charge.success event.
 */
class PaystackGateway implements PaymentGatewayContract
{
    public function initializeDeposit(
        User $user,
        int $amountKobo,
        string $reference,
        array $channels,
        string $callbackUrl,
    ): PaymentInitialization {
        $response = Http::withToken((string) config('services.paystack.secret_key'))
            ->asJson()
            ->post(config('services.paystack.base_url').'/transaction/initialize', [
                'email' => $user->email ?? $user->phone.'@savings.FirstMaket.ng',
                'amount' => $amountKobo, // Paystack expects the smallest unit (kobo).
                'currency' => 'NGN',
                'reference' => $reference,
                'channels' => $channels,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'user_uuid' => $user->uuid,
                    'purpose' => 'firstmaket_payment',
                ],
            ]);

        if (! $response->successful() || $response->json('status') !== true) {
            throw new RuntimeException($response->json('message') ?? 'Could not start the payment. Please try again.');
        }

        return new PaymentInitialization(
            reference: (string) $response->json('data.reference'),
            authorizationUrl: (string) $response->json('data.authorization_url'),
            accessCode: $response->json('data.access_code'),
        );
    }

    /**
     * Charge a saved card (https://paystack.com/docs/api/transaction/#charge-authorization).
     *
     * A network failure or an HTTP error is reported as a failed attempt
     * rather than thrown: this runs unattended from the scheduler, and one
     * customer's card being declined must not abort the run for everybody
     * behind them. The caller decides whether that means "retry tomorrow" or
     * "ask for the card again".
     */
    public function chargeAuthorization(
        User $user,
        string $authorizationCode,
        int $amountKobo,
        string $reference,
    ): ChargeAttempt {
        try {
            $response = Http::withToken((string) config('services.paystack.secret_key'))
                ->asJson()
                ->post(config('services.paystack.base_url').'/transaction/charge_authorization', [
                    'authorization_code' => $authorizationCode,
                    'email' => $user->email ?? $user->phone.'@savings.FirstMaket.ng',
                    'amount' => $amountKobo, // Kobo, as everywhere else.
                    'currency' => 'NGN',
                    'reference' => $reference,
                    'metadata' => [
                        'user_uuid' => $user->uuid,
                        'purpose' => 'firstmaket_automatic_debit',
                    ],
                ]);
        } catch (\Throwable $e) {
            return new ChargeAttempt(false, 'unreachable', 'Could not reach the payment provider.');
        }

        if (! $response->successful() || $response->json('status') !== true) {
            return new ChargeAttempt(
                false,
                'rejected',
                (string) ($response->json('message') ?? 'The payment provider rejected the charge.'),
            );
        }

        $status = (string) ($response->json('data.status') ?? '');

        return new ChargeAttempt(
            succeeded: $status === 'success',
            status: $status,
            message: $status === 'success'
                ? null
                : (string) ($response->json('data.gateway_response') ?? 'The card was declined.'),
        );
    }

    /**
     * Reverse a settled charge (https://paystack.com/docs/api/refund/).
     *
     * Paystack refunds against the original transaction reference, which is
     * what keeps this from being a payout: the money can only travel back the
     * way it came. The amount is passed explicitly so a partial return refunds
     * only its own share.
     *
     * Like the automatic-debit charge, a transport failure comes back as a
     * failed attempt rather than an exception — the caller records it against
     * the refund row and an admin retries, which is safe because the refund
     * reference is unique.
     */
    public function refund(string $transactionReference, int $amountKobo): ChargeAttempt
    {
        try {
            $response = Http::withToken((string) config('services.paystack.secret_key'))
                ->asJson()
                ->post(config('services.paystack.base_url').'/refund', [
                    'transaction' => $transactionReference,
                    'amount' => $amountKobo,
                    'currency' => 'NGN',
                ]);
        } catch (\Throwable $e) {
            return new ChargeAttempt(false, 'unreachable', 'Could not reach the payment provider.');
        }

        if (! $response->successful() || $response->json('status') !== true) {
            return new ChargeAttempt(
                false,
                'rejected',
                (string) ($response->json('message') ?? 'The payment provider rejected the refund.'),
            );
        }

        // Paystack queues a refund and settles it later, so "processed" and
        // "pending" are both acceptable outcomes here — neither is a failure.
        $status = (string) ($response->json('data.status') ?? 'pending');

        return new ChargeAttempt(
            succeeded: in_array($status, ['processed', 'success'], true),
            status: $status,
            message: null,
        );
    }

    public function verifySignature(string $rawPayload, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawPayload, (string) config('services.paystack.secret_key'));

        return hash_equals($expected, $signature);
    }

    /**
     * Ask Paystack what became of a charge
     * (https://paystack.com/docs/api/transaction/#verify).
     *
     * Never throws. A timeout, a 500, or a rate-limit answer all mean the
     * same thing — we do not know — and the caller must be able to tell that
     * apart from "Paystack says it failed", because only one of those is
     * grounds to forget a payment record.
     *
     * A 404 is different and is reported as dead: Paystack has looked and has
     * no such transaction, which for a reference we generated ourselves means
     * the customer never got as far as paying.
     */
    public function verifyTransaction(string $reference): TransactionSnapshot
    {
        try {
            $response = Http::withToken((string) config('services.paystack.secret_key'))
                ->timeout(15)
                ->acceptJson()
                ->get(config('services.paystack.base_url').'/transaction/verify/'.urlencode($reference));
        } catch (\Throwable) {
            return TransactionSnapshot::unreachable();
        }

        if ($response->status() === 404) {
            return new TransactionSnapshot(status: 'not_found');
        }

        if (! $response->successful() || $response->json('status') !== true) {
            return TransactionSnapshot::unreachable();
        }

        $data = is_array($response->json('data')) ? $response->json('data') : [];

        return new TransactionSnapshot(
            status: strtolower((string) ($data['status'] ?? 'unknown')),
            amountKobo: (int) ($data['amount'] ?? 0),
            channel: is_string($data['channel'] ?? null) ? $data['channel'] : null,
            payload: $data,
        );
    }
}
