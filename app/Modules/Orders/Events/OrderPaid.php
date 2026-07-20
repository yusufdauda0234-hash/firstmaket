<?php

namespace App\Modules\Orders\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a paid order is created from a fully funded plan. The Vendor
 * module listens to send the "item sold" notification (product, order
 * number — never customer identity). Sprint 7+ notification center will
 * subscribe too.
 */
class OrderPaid implements DomainEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $vendorId,
        public readonly int $productId,
        public readonly int $lockedPriceKobo,
    ) {}
}
