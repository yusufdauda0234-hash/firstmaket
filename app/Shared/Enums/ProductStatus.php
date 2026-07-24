<?php

namespace App\Shared\Enums;

/**
 * Product listing state machine (docs/FirstMaket_Implementation_Plan.md
 * Sprint 3): Draft → Pending Approval → Approved/Rejected; Approved can be
 * Delisted (vendor suspension, admin action) or fall back to Pending
 * Approval when the vendor edits the price.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Delisted = 'delisted';
}
