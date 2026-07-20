<?php

namespace App\Shared\Enums;

/**
 * Logistics assignment lifecycle for an order
 * (docs/firstmarket-Database_Schema.md section 9).
 */
enum DeliveryAssignmentStatus: string
{
    case Assigned = 'assigned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
