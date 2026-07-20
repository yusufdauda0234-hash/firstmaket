<?php

namespace App\Shared\Enums;

/**
 * Aggregate identity state on a profile. Product Target Plan activation is
 * blocked until this is Verified; Open Savings may start earlier
 * (docs/firstmarket_Implementation_Plan.md Sprint 2 QA notes).
 */
enum IdentityStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
