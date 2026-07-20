<?php

namespace App\Shared\Enums;

/**
 * Contribution schedule for a Product Target Plan in schedule mode
 * (docs/firstmarket-Database_Schema.md section 8). Null on Pay At Once plans.
 */
enum PlanCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /** Days per cycle, used for expected-completion-date projection. */
    public function intervalDays(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
            self::Monthly => 30,
        };
    }
}
