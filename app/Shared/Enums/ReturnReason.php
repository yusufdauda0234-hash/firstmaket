<?php

namespace App\Shared\Enums;

/**
 * Why an item is coming back.
 *
 * The taxonomy is not decoration: it decides who pays the return delivery and
 * whether an otherwise excluded category may be returned at all. The policy
 * printed on every product page says the platform covers the return when the
 * item arrived damaged, faulty or not as described, and that the customer
 * covers it when they simply changed their mind — so the reason has to be a
 * closed set the code can actually reason about, not free text.
 */
enum ReturnReason: string
{
    case Damaged = 'damaged';
    case Faulty = 'faulty';
    case NotAsDescribed = 'not_as_described';
    case WrongItem = 'wrong_item';
    case MissingParts = 'missing_parts';
    case ChangedMind = 'changed_mind';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Arrived damaged',
            self::Faulty => 'Faulty or not working',
            self::NotAsDescribed => 'Not what was described',
            self::WrongItem => 'Wrong item sent',
            self::MissingParts => 'Parts or accessories missing',
            self::ChangedMind => 'Changed my mind',
        };
    }

    /**
     * True when the fault is ours or the vendor's, so FirstMaket pays the
     * return delivery and refunds in full.
     *
     * Everything except a change of mind. Stated as "not ChangedMind" rather
     * than a list, so a reason added later is treated as a fault by default —
     * the safer way to be wrong, since the alternative is silently charging a
     * customer for a return that was never their doing.
     */
    public function isOurFault(): bool
    {
        return $this !== self::ChangedMind;
    }

    /** Who pays to send it back. */
    public function returnDeliveryPaidBy(): string
    {
        return $this->isOurFault() ? 'platform' : 'customer';
    }

    /**
     * Whether this reason can override a category's returns exclusion.
     *
     * Perishables, underwear, pierced jewellery and made-to-order items cannot
     * be returned on a change of mind, but the law and the published policy
     * both still allow a return when they arrive faulty.
     */
    public function overridesCategoryExclusion(): bool
    {
        return in_array($this, [self::Damaged, self::Faulty, self::WrongItem, self::MissingParts], true);
    }

    /** Change-of-mind returns are only accepted unopened. */
    public function requiresUnopened(): bool
    {
        return $this === self::ChangedMind;
    }
}
