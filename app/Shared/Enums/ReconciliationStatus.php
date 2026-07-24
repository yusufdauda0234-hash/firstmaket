<?php

namespace App\Shared\Enums;

/**
 * Result of matching a Paystack settlement line against the internal ledger
 * (docs/FirstMaket-Database_Schema.md section 7).
 */
enum ReconciliationStatus: string
{
    case Matched = 'matched';
    case MissingInLedger = 'missing_in_ledger';
    case MissingInProvider = 'missing_in_provider';
    case AmountMismatch = 'amount_mismatch';
}
