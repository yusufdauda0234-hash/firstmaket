<?php

namespace App\Shared\Contracts;

/**
 * Bank account verification for vendor payouts
 * (docs/firstmarket_Implementation_Plan.md Sprint 6): resolve the account
 * name for a number+bank pair and register a transfer recipient with the
 * provider. Kept separate from PaymentGatewayContract because customer-side
 * payments deliberately expose no payout surface.
 */
interface BankAccountResolverContract
{
    /**
     * Resolve the account holder name, or null when the account cannot be
     * verified.
     */
    public function resolveAccountName(string $accountNumber, string $bankCode): ?string;

    /**
     * Create (or reuse) a provider transfer recipient and return its code,
     * or null on failure.
     */
    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): ?string;
}
