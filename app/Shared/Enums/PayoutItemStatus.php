<?php

namespace App\Shared\Enums;

/**
 * Per-vendor line inside a payout batch (docs/firstmarket-Database_Schema.md
 * section 9). A failed transfer keeps the ledger intact — the payout ledger
 * debit is written only when the item is marked paid.
 */
enum PayoutItemStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Failed = 'failed';
}
