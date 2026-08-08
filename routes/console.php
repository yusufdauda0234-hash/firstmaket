<?php

use App\Modules\Orders\Commands\AutoConfirmDeliveredOrders;
use App\Modules\Orders\Commands\FlagOverduePreparations;
use App\Modules\Savings\Commands\RevokeUnpaidPlans;
use App\Modules\Savings\Commands\SweepDormantPlans;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sprint 6 fulfillment schedulers (docs/FirstMaket_Implementation_Plan.md):
// auto-confirm delivered orders after the confirmation window, and flag
// vendor preparations that miss the packing SLA. Both are idempotent.
Schedule::command(AutoConfirmDeliveredOrders::class)->hourly();
Schedule::command(FlagOverduePreparations::class)->hourly();

// A plan holds a price at today's rate, so one whose first payment never
// arrives has to be released rather than left open. Also idempotent.
Schedule::command(RevokeUnpaidPlans::class)->hourly();

// Warn, then close, plans nobody is paying into. Daily rather than hourly:
// the two passes are meant to be a day apart so the warning has time to
// be read and acted on before the plan is let go.
Schedule::command(SweepDormantPlans::class)->dailyAt('09:00');
