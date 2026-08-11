<?php

namespace App\Modules\Affiliates\Listeners;

use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Orders\Events\OrderDeliveryConfirmed;
use App\Modules\Orders\Models\Order;

class QualifyAffiliateOrder
{
    public function __construct(private readonly AffiliateService $affiliates) {}

    public function handle(OrderDeliveryConfirmed $event): void
    {
        $order = Order::query()->find($event->orderId);

        if ($order !== null) {
            $this->affiliates->qualifyDeliveredOrder($order);
        }
    }
}
