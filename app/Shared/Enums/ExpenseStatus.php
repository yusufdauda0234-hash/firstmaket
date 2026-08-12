<?php

namespace App\Shared\Enums;

/**
 * Where an expense sits between "somebody spent this" and "the business
 * accepts it".
 *
 * Recording and approving are separate on purpose: the person who spends the
 * money should not be the person who signs it off, and a total that mixes
 * unreviewed claims into the same figure as approved spend is not a number
 * anybody can act on.
 */
enum ExpenseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /** Rejected spend is not spend — it never counts toward a total. */
    public function countsTowardSpend(): bool
    {
        return $this !== self::Rejected;
    }
}
