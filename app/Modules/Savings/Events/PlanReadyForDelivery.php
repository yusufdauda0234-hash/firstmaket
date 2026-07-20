<?php

namespace App\Modules\Savings\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired the moment a Product Target Plan (schedule or Pay At Once) reaches
 * 100% of its locked target price. Sprint 6's Orders module listens to this
 * to create the order and notify the vendor — the Savings module does not
 * know Orders exists.
 */
class PlanReadyForDelivery implements DomainEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $planId,
        public readonly int $userId,
        public readonly int $productId,
        public readonly int $targetPriceKobo,
    ) {}
}
