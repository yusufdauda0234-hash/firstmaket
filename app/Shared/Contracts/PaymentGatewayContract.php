<?php

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * Payment provider abstraction (docs/firstmarket_Developer_Guidelines.md
 * golden rules — modules depend on Shared contracts, not concrete drivers).
 * The MVP driver is Paystack; nothing here exposes a payout/withdrawal
 * operation, because none exists in the system.
 */
interface PaymentGatewayContract
{
    /**
     * Start a hosted deposit charge and return the checkout hand-off.
     *
     * @param  list<string>  $channels  Paystack channel identifiers to allow.
     */
    public function initializeDeposit(
        User $user,
        int $amountKobo,
        string $reference,
        array $channels,
        string $callbackUrl,
    ): PaymentInitialization;

    /** Verify a webhook body against the provider signature header. */
    public function verifySignature(string $rawPayload, string $signature): bool;
}
