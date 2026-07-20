<?php

namespace App\Shared\Enums;

/**
 * Vendor-side preparation trail for an order
 * (docs/firstmarket-Database_Schema.md section 9). Append-only events; the
 * SLA breach row is written once by the scheduler when packing runs past
 * the deadline.
 */
enum VendorPreparationStatus: string
{
    case Notified = 'notified';
    case StockConfirmed = 'stock_confirmed';
    case ReadyForPickup = 'ready_for_pickup';
    case Rejected = 'rejected';
    case SlaBreached = 'sla_breached';
}
