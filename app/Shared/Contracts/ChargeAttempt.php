<?php

namespace App\Shared\Contracts;

/**
 * The outcome of charging a stored card authorization.
 *
 * `succeeded` means the provider accepted the charge, not that the plan has
 * been credited — crediting only ever happens when the signature-verified
 * webhook arrives, exactly as it does for a hosted charge. This object only
 * tells the caller whether to advance the schedule or start the retry clock.
 */
final readonly class ChargeAttempt
{
    /**
     * Statuses that mean "the bank has not finished with this yet".
     *
     * These are neither a success nor a failure, and treating them as either
     * is a way to take someone's money twice: count it as failed and we retry
     * tomorrow, while the original charge quietly completes and the webhook
     * credits it — two charges for one instalment.
     */
    private const IN_FLIGHT = ['pending', 'ongoing', 'processing', 'queued'];

    public function __construct(
        public bool $succeeded,
        /** Provider status string, e.g. "success", "failed", "abandoned". */
        public ?string $status = null,
        /** Human-readable reason, safe to show a customer. */
        public ?string $message = null,
    ) {}

    /** Still with the bank — the outcome will arrive by webhook. */
    public function isInFlight(): bool
    {
        return ! $this->succeeded
            && $this->status !== null
            && in_array(strtolower($this->status), self::IN_FLIGHT, true);
    }
}
