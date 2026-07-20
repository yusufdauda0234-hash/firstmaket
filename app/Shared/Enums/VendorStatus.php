<?php

namespace App\Shared\Enums;

/**
 * Vendor lifecycle from docs/firstmarket-Database_Schema.md: vendors cannot
 * list products unless Approved (enforced from Sprint 3 onward).
 */
enum VendorStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Banned = 'banned';
}
