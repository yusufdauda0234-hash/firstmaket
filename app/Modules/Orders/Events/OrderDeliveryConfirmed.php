<?php

namespace App\Modules\Orders\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the customer confirms receipt (or the auto-confirm window
 * closes). The Vendor module listens to credit the vendor earnings ledger —
 * commission was already snapshotted on the order at creation.
 */
class OrderDeliveryConfirmed implements DomainEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $vendorId,
        public readonly int $vendorEarningAmountKobo,
    ) {}
}
