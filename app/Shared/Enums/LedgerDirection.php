<?php

namespace App\Shared\Enums;

/**
 * Direction of a ledger entry relative to the savings balance
 * (docs/FirstMaket-Database_Schema.md section 7).
 */
enum LedgerDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
