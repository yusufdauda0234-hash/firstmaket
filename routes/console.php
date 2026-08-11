<?php

use App\Modules\Orders\Commands\AutoConfirmDeliveredOrders;
use App\Modules\Orders\Commands\FlagOverduePreparations;
use App\Modules\Payments\Commands\ChargeDueAutomaticDebits;
use App\Modules\Payments\Commands\ReconcilePendingPayments;
use App\Modules\Risk\Commands\SweepRiskFlags;
use App\Modules\Vendor\Commands\RecalculateVendorRatings;
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

/*
 * Phase 2B automatic debit.
 *
 * Once a day, and early: a card declined at 07:00 leaves the customer the rest
 * of the day to notice and fix it before the single retry 24 hours later.
 * `withoutOverlapping` because a slow provider must not let a second run start
 * charging the same rows — the command is idempotent by date, but there is no
 * reason to have two of them talking to Paystack at once.
 */
Schedule::command(ChargeDueAutomaticDebits::class)
    ->dailyAt('07:00')
    ->withoutOverlapping();

/*
 * Payments the webhook never reached us about.
 *
 * Every fifteen minutes rather than nightly: a customer who has paid and
 * sees nothing credited will not wait until tomorrow before deciding the
 * site is broken. `withoutOverlapping` because each run makes an outbound
 * call per unresolved charge, and two runs racing would double that traffic
 * against Paystack's rate limit for no benefit.
 */
Schedule::command(ReconcilePendingPayments::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/*
 * Phase 2D. Both run overnight and both are safe to repeat: the rating is a
 * pure function of stored facts, and a risk flag that is already open and
 * unreviewed cannot be raised twice.
 */
Schedule::command(RecalculateVendorRatings::class)->dailyAt('02:00');
Schedule::command(SweepRiskFlags::class)->dailyAt('02:30');
