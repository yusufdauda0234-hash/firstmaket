<?php

namespace App\Modules\Savings\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired the moment a Product Target Plan (schedule or Pay At Once) reaches
 * 100% of its locked target price — including, since Sprint 8, a
 * multi-product bundle, where $productId is null (its products live in
 * plan_items instead; see PlanItem). The customer still submits a delivery
 * address afterward (OrderController::store), which creates the order(s)
 * and notifies the vendor(s) — the Savings module does not know Orders
 * exists.
 */
class PlanReadyForDelivery implements DomainEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $planId,
        public readonly int $userId,
        public readonly ?int $productId,
        public readonly int $targetPriceKobo,
    ) {}
}
