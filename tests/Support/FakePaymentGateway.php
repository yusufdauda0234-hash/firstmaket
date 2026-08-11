<?php

namespace Tests\Support;

use App\Models\User;
use App\Shared\Contracts\ChargeAttempt;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Contracts\PaymentInitialization;
use App\Shared\Contracts\TransactionSnapshot;

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

    /** Authorization codes passed to chargeAuthorization(), in order. */
    public array $authorizationCharges = [];

    /** Flip on to make every stored-card charge come back declined. */
    public bool $declineCharges = false;

    /** Flip on to return a charge the bank has accepted but not settled. */
    public bool $pendingCharges = false;

    /** @var list<array{reference: string, amountKobo: int}> */
    public array $refunds = [];

    /** Flip on to make every refund come back rejected. */
    public bool $declineRefunds = false;

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

    /**
     * Stored-card charge. Succeeds unless a test sets {@see $declineCharges},
     * which is how the automatic-debit retry path is exercised without a real
     * declined card.
     */
    public function chargeAuthorization(
        User $user,
        string $authorizationCode,
        int $amountKobo,
        string $reference,
    ): ChargeAttempt {
        $this->charges[] = ['user' => $user, 'amountKobo' => $amountKobo, 'reference' => $reference];
        $this->authorizationCharges[] = $authorizationCode;

        if ($this->declineCharges) {
            return new ChargeAttempt(false, 'failed', 'Insufficient funds.');
        }

        // Not succeeded, but not a failure either — the service must treat
        // this as in flight rather than something to retry.
        if ($this->pendingCharges) {
            return new ChargeAttempt(false, 'pending', 'Awaiting the bank.');
        }

        return new ChargeAttempt(true, 'success');
    }

    /** Reversals, recorded so a test can assert what was sent back. */
    public function refund(string $transactionReference, int $amountKobo): ChargeAttempt
    {
        $this->refunds[] = ['reference' => $transactionReference, 'amountKobo' => $amountKobo];

        return $this->declineRefunds
            ? new ChargeAttempt(false, 'rejected', 'Refund rejected by provider.')
            : new ChargeAttempt(true, 'processed');
    }

    public function verifySignature(string $rawPayload, string $signature): bool
    {
        return true;
    }

    /**
     * What this fake provider will say about a given reference.
     *
     * Keyed by reference so one test can stage several outcomes at once —
     * a reference that quietly succeeded, one the customer abandoned, and one
     * still with the bank — which is exactly the mix the reconciler has to
     * tell apart.
     *
     * @var array<string, TransactionSnapshot>
     */
    public array $snapshots = [];

    /** References the reconciler asked about, in order. */
    public array $verified = [];

    /** Flip on to simulate Paystack being unreachable. */
    public bool $unreachable = false;

    public function stageSuccess(string $reference, int $amountKobo, string $channel = 'card'): void
    {
        $this->snapshots[$reference] = new TransactionSnapshot(
            status: 'success',
            amountKobo: $amountKobo,
            channel: $channel,
            payload: ['reference' => $reference, 'status' => 'success', 'amount' => $amountKobo, 'currency' => 'NGN'],
        );
    }

    public function stageStatus(string $reference, string $status): void
    {
        $this->snapshots[$reference] = new TransactionSnapshot(status: $status);
    }

    public function verifyTransaction(string $reference): TransactionSnapshot
    {
        $this->verified[] = $reference;

        if ($this->unreachable) {
            return TransactionSnapshot::unreachable();
        }

        // Nothing staged means Paystack has never heard of it — the shape of
        // a reference we created but the customer never paid.
        return $this->snapshots[$reference] ?? new TransactionSnapshot(status: 'not_found');
    }

    /** Kobo handed to the gateway on the most recent charge. */
    public function lastAmountKobo(): ?int
    {
        return $this->charges === [] ? null : end($this->charges)['amountKobo'];
    }
}
