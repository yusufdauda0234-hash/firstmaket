<?php

namespace App\Shared\Contracts;

/**
 * Result of initializing a hosted payment — what the browser needs to hand
 * off to the provider's checkout.
 */
final class PaymentInitialization
{
    public function __construct(
        public readonly string $reference,
        public readonly string $authorizationUrl,
        public readonly ?string $accessCode = null,
    ) {}
}
