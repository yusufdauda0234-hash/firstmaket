<?php

namespace App\Shared\Enums;

/**
 * Product Target Plan lifecycle (docs/firstmarket-Database_Schema.md section
 * 8). Redirection/product-switching is only allowed while Active; once Ready
 * for Delivery the balance is committed to the product and Sprint 6 turns it
 * into an order (Completed).
 */
enum PlanStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case ReadyForDelivery = 'ready_for_delivery';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
