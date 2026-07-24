<?php

namespace App\Modules\Payments\Services;

use App\Models\User;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\PaymentInitialization;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paystack payment driver (https://paystack.com/docs/api/transaction/).
 * Initializes hosted charges and verifies webhook signatures. The wallet is
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
                'email' => $user->email ?? $user->phone.'@wallet.FirstMaket.ng',
                'amount' => $amountKobo, // Paystack expects the smallest unit (kobo).
                'currency' => 'NGN',
                'reference' => $reference,
                'channels' => $channels,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'user_uuid' => $user->uuid,
                    'purpose' => 'wallet_deposit',
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

    public function verifySignature(string $rawPayload, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawPayload, (string) config('services.paystack.secret_key'));

        return hash_equals($expected, $signature);
    }
}
