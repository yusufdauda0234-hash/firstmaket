<?php

namespace App\Modules\Vendor\Listeners;

use App\Modules\Orders\Events\OrderDeliveryConfirmed;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Notifications\EarningsCreditedNotification;
use App\Modules\Vendor\Services\EarningsService;
use App\Shared\Utils\Money;

/**
 * Vendor module reaction to a confirmed delivery
 * (docs/FirstMaket_Implementation_Plan.md Sprint 6 step 8): credit the
 * earnings ledger exactly once (EarningsService is idempotent per order),
 * stamp the order, and tell the vendor.
 */
class CreditVendorEarnings
{
    public function __construct(private readonly EarningsService $earningsService) {}

    public function handle(OrderDeliveryConfirmed $event): void
    {
        $earning = $this->earningsService->creditOrderEarning(
            vendorId: $event->vendorId,
            orderId: $event->orderId,
            amountKobo: $event->vendorEarningAmountKobo,
        );

        if ($earning === null) {
            return; // Already credited — exactly-once guarantee held.
        }

        $order = Order::query()->with('vendor.user')->find($event->orderId);

        if ($order !== null) {
            $order->forceFill(['earnings_credited_at' => now()])->save();

            $order->vendor->user->notify(new EarningsCreditedNotification(
                orderNumber: $order->uuid,
                amountNaira: Money::formatKobo($event->vendorEarningAmountKobo),
            ));
        }
    }
}
