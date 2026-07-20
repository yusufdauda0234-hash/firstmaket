<?php

namespace App\Modules\Vendor\Listeners;

use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Notifications\ItemSoldNotification;
use App\Shared\Utils\Money;

/**
 * Vendor module reaction to a paid order (docs/firstmarket_Implementation_Plan.md
 * Sprint 6 step 2): email the vendor "item sold" with product and order
 * number — never customer identity. The vendor dashboard list reads the
 * orders table directly.
 */
class NotifyVendorOfSale
{
    public function handle(OrderPaid $event): void
    {
        $order = Order::query()->with(['vendor.user', 'product'])->find($event->orderId);

        if ($order === null) {
            return;
        }

        $order->forceFill(['vendor_notified_at' => now()])->save();

        $order->vendor->user->notify(new ItemSoldNotification(
            orderNumber: $order->uuid,
            productName: $order->product->name,
            amountNaira: Money::formatKobo($order->locked_price_kobo),
        ));
    }
}
