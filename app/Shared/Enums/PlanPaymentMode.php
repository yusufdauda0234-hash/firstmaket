<?php

namespace App\Shared\Enums;

/**
 * How a Product Target Plan is funded (docs/FirstMaket-Database_Schema.md
 * section 8). Pay At Once is modeled as a plan with this mode rather than a
 * separate direct_checkouts table, per the schema doc's stated option — the
 * customer pays the full locked price in one wallet allocation and the plan
 * goes straight to Ready for Delivery.
 */
enum PlanPaymentMode: string
{
    case Schedule = 'schedule';
    case PayAtOnce = 'pay_at_once';
}
