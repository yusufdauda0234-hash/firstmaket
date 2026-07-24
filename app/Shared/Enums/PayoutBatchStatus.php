<?php

namespace App\Shared\Enums;

/**
 * Weekly vendor payout batch lifecycle (docs/FirstMaket-Database_Schema.md
 * section 9): generated as a draft, submitted for Finance approval, then
 * processed item by item.
 */
enum PayoutBatchStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
