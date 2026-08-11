<?php

namespace App\Shared\Contracts;

/**
 * What the provider says about a charge when we go and ask it directly.
 *
 * Used to settle the question a webhook cannot answer on its own: "the
 * customer says they paid, and we have no webhook — did the money arrive?"
 * An outbound authenticated call is at least as trustworthy as an inbound
 * signed one, so a snapshot that reports success is sufficient grounds to
 * credit, through exactly the same idempotent path a webhook would take.
 *
 * The three outcomes are deliberately distinct, because they have opposite
 * consequences:
 *
 *  - **succeeded** — money arrived. Credit it. Never delete this record.
 *  - **inFlight** — the bank has not finished. Leave it completely alone;
 *    deleting it would orphan a payment that is still about to land.
 *  - **dead** — failed, abandoned, or the provider has never heard of it.
 *    It can never become money, so the row is safe to remove.
 */
final readonly class TransactionSnapshot
{
    /** Provider statuses that mean the outcome is not yet decided. */
    private const IN_FLIGHT = ['pending', 'ongoing', 'processing', 'queued'];

    /** Provider statuses that mean this will never become money. */
    private const DEAD = ['failed', 'abandoned', 'reversed', 'cancelled', 'not_found'];

    public function __construct(
        /** Provider status string, lowercased, e.g. "success". */
        public string $status,
        /** What the provider says was actually paid, in kobo. */
        public int $amountKobo = 0,
        public ?string $channel = null,
        /** The provider's own record, stored verbatim for later dispute. */
        public array $payload = [],
        /**
         * True when we could not reach the provider at all.
         *
         * Distinct from "the provider says it failed": an unreachable
         * provider tells us nothing, and nothing is exactly what should be
         * concluded from it. A reconciler must never treat a timeout as
         * grounds to delete a payment record.
         */
        public bool $unreachable = false,
    ) {}

    public static function unreachable(): self
    {
        return new self(status: 'unknown', unreachable: true);
    }

    public function succeeded(): bool
    {
        return ! $this->unreachable && $this->status === 'success';
    }

    public function isInFlight(): bool
    {
        return ! $this->unreachable && in_array($this->status, self::IN_FLIGHT, true);
    }

    /**
     * Safe to forget: the provider has definitively said this will never
     * become money. Never true when the provider could not be reached.
     */
    public function isDead(): bool
    {
        return ! $this->unreachable && in_array($this->status, self::DEAD, true);
    }
}
