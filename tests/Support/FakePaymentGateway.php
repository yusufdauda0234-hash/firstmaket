<?php

namespace Tests\Support;

use App\Models\User;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\PaymentInitialization;

/**
 * Stands in for Paystack in tests that walk a route ending in a redirect to
 * the hosted checkout.
 *
 * Without it those tests make a real HTTP call to Paystack and fail on its
 * rate limit — a failure that says nothing about the code under test.
 * Records what it was asked to charge so a test can assert the amount.
 */
class FakePaymentGateway implements PaymentGatewayContract
{
    /** @var list<array{user: User, amountKobo: int, reference: string}> */
    public array $charges = [];

    public function initializeDeposit(
        User $user,
        int $amountKobo,
        string $reference,
        array $channels,
        string $callbackUrl,
    ): PaymentInitialization {
        $this->charges[] = ['user' => $user, 'amountKobo' => $amountKobo, 'reference' => $reference];

        return new PaymentInitialization(
            authorizationUrl: 'https://checkout.paystack.test/'.$reference,
            accessCode: 'ACCESS-'.$reference,
            reference: $reference,
        );
    }

    public function verifySignature(string $rawPayload, string $signature): bool
    {
        return true;
    }

    /** Kobo handed to the gateway on the most recent charge. */
    public function lastAmountKobo(): ?int
    {
        return $this->charges === [] ? null : end($this->charges)['amountKobo'];
    }
}
