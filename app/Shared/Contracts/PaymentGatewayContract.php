<?php

namespace App\Shared\Contracts;

use App\Models\User;


/**
 * Payment provider abstraction (docs/FirstMaket_Developer_Guidelines.md
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

    /**
     * Charge a stored, reusable card authorization without the customer
     * present — Phase 2B automatic debit.
     *
     * Still not a payout: money only ever moves from the customer to
     * FirstMaket, and nothing here can send it the other way. As with a hosted
     * charge, this does not credit anything; the verified webhook does.
     */
    public function chargeAuthorization(
        User $user,
        string $authorizationCode,
        int $amountKobo,
        string $reference,
    ): ChargeAttempt;

    /**
     * Reverse part or all of a charge that already settled — Phase 2E.
     *
     * The only outward money operation in the system, and deliberately not a
     * payout: it can only send money back along the exact transaction that
     * brought it in, never to an arbitrary destination. There is still no way
     * to withdraw, cash out, or pay a third party through this interface.
     *
     * @param  string  $transactionReference  The original charge being reversed.
     */
    public function refund(string $transactionReference, int $amountKobo): ChargeAttempt;

    /** Verify a webhook body against the provider signature header. */
    public function verifySignature(string $rawPayload, string $signature): bool;

    /**
     * Ask the provider directly what became of one charge.
     *
     * The counterpart to the webhook: a webhook that never arrives — dropped,
     * blocked, or sent while the app was down — leaves a customer who has
     * paid looking, to us, like a customer who has not. This closes that gap
     * without waiting for a support ticket.
     *
     * Implementations must never throw on a network failure. An unreachable
     * provider has told us nothing, and a reconciler acting on "nothing" as
     * though it meant "failed" would delete a record of real money.
     */
    public function verifyTransaction(string $reference): TransactionSnapshot;
}
