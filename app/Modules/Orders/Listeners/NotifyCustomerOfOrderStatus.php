<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Notifications\OrderStatusNotification;
use App\Shared\Enums\OrderStatus;

/**
 * Customer notification on every order status transition
 * (docs/FirstMaket_Implementation_Plan.md Sprint 6 step 6).
 */
class NotifyCustomerOfOrderStatus
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = Order::query()->with(['customer', 'product'])->find($event->orderId);

        if ($order === null) {
            return;
        }

        $status = OrderStatus::tryFrom($event->newStatus);

        $order->customer->notify(new OrderStatusNotification(
            orderNumber: $order->uuid,
            productName: $order->product->name,
            statusLabel: $status?->label() ?? $event->newStatus,
        ));
    }
}
