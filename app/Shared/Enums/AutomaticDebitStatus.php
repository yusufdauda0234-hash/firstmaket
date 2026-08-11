<?php

namespace App\Shared\Enums;

/**
 * Lifecycle of a scheduled automatic debit.
 *
 * `NeedsReauthorization` is deliberately distinct from `Cancelled`: the
 * customer still wants the debit, the card just stopped working. Collapsing
 * the two would lose the difference between "they turned this off" and "we
 * need to ask them for a card", and only one of those is worth prompting
 * about.
 */
enum AutomaticDebitStatus: string
{
    case Active = 'active';
    case NeedsReauthorization = 'needs_reauthorization';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'On',
            self::NeedsReauthorization => 'Card needs re-authorising',
            self::Cancelled => 'Off',
        };
    }
}
