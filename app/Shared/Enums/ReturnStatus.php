<?php

namespace App\Shared\Enums;

/**
 * The life of a return request.
 *
 * Requested → Approved → InTransit → Received → (Refunded | Rejected), with
 * Disputed hanging off the inspection step for when the vendor says the item
 * came back in a different condition than the customer described.
 *
 * Rejected and Refunded are both terminal, and only an admin reaches either
 * from a disputed case — a vendor can contest an inspection but cannot decide
 * it, because the vendor is the party with an interest in the outcome.
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Disputed = 'disputed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Awaiting review',
            self::Approved => 'Approved — send it back',
            self::Rejected => 'Not approved',
            self::InTransit => 'On its way back',
            self::Received => 'Received — being checked',
            self::Disputed => 'Under review',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Nothing further happens to a case in one of these states. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Refunded, self::Cancelled], true);
    }

    /**
     * The customer may still call the request off themselves.
     *
     * Named `cancel` rather than `withdraw` throughout: in a savings product
     * "withdraw" has to mean exactly one thing, and that thing is forbidden.
     * An architecture test scans the route table for the word.
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Requested, self::Approved], true);
    }
}
