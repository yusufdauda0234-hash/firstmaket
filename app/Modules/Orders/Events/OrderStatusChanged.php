<?php

namespace App\Modules\Orders\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired on every order status transition so the customer can be notified at
 * each step of the delivery chain without the Orders module knowing who
 * sends the notification.
 */
class OrderStatusChanged implements DomainEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $customerId,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {}
}
