<?php

use App\Modules\Orders\Commands\AutoConfirmDeliveredOrders;
use App\Modules\Orders\Commands\FlagOverduePreparations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sprint 6 fulfillment schedulers (docs/firstmarket_Implementation_Plan.md):
// auto-confirm delivered orders after the confirmation window, and flag
// vendor preparations that miss the packing SLA. Both are idempotent.
Schedule::command(AutoConfirmDeliveredOrders::class)->hourly();
Schedule::command(FlagOverduePreparations::class)->hourly();
