<?php

namespace App\Shared\Enums;

/**
 * Vendor earnings ledger row types (docs/FirstMaket-Database_Schema.md
 * section 9). `earning` rows are positive and unique per order; `payout`
 * rows are negative; corrections are new `adjustment` rows — never edits.
 * This ledger is fully separate from customer savings.
 */
enum VendorEarningType: string
{
    case Earning = 'earning';
    case Adjustment = 'adjustment';
    case Payout = 'payout';
}
