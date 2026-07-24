<?php

namespace App\Modules\Orders\Commands;

use App\Models\Setting;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Shared\Enums\OrderStatus;
use Illuminate\Console\Command;

/**
 * Delivery confirmation window (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6 step 7): delivered orders auto-confirm after N days (default 3)
 * without a customer complaint, which releases the vendor earning credit.
 * Scheduled hourly in routes/console.php.
 */
class AutoConfirmDeliveredOrders extends Command
{
    protected $signature = 'orders:auto-confirm';

    protected $description = 'Confirm delivered orders whose confirmation window has passed';

    public function handle(OrderService $orderService): int
    {
        $days = (int) Setting::get('orders.auto_confirm_days', 3);
        $confirmed = 0;

        Order::query()
            ->where('status', OrderStatus::Delivered)
            ->whereNull('delivery_confirmed_at')
            ->where('delivered_at', '<=', now()->subDays($days))
            ->each(function (Order $order) use ($orderService, &$confirmed) {
                $orderService->confirmDelivery(null, $order, 'Auto-confirmed after delivery window');
                $confirmed++;
            });

        $this->info("Auto-confirmed {$confirmed} order(s).");

        return self::SUCCESS;
    }
}
