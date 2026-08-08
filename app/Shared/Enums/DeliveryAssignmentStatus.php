<?php

namespace App\Shared\Enums;

/**
 * Logistics assignment lifecycle for an order
 * (docs/FirstMaket-Database_Schema.md section 9).
 */
enum DeliveryAssignmentStatus: string
{
    case Assigned = 'assigned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    /**
     * The courier tried and could not hand it over. Distinct from Cancelled,
     * which means the assignment was withdrawn: a failed run is work that
     * happened and has to be counted as such, and the shipment goes back to
     * the dispatch queue rather than disappearing.
     */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned',
            self::Completed => 'Completed',
            self::Cancelled => 'Reassigned',
            self::Failed => 'Attempt failed',
        };
    }
}
