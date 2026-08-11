<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Payments\Models\AutomaticDebit;
use App\Modules\Returns\Services\ReturnPolicy;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The operational numbers are settings, not constants.
 *
 * The tests that matter here are not "the form saves" but "the saved value
 * changes what the system does" — a settings screen that writes a row nothing
 * reads is worse than a hardcoded value, because it looks like it worked.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create(['user_type' => UserType::Staff]);
    $this->admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->admin->assignRole('Super Administrator');
});

it('falls back to the shipped defaults when nothing has been set', function () {
    expect(app(ReturnPolicy::class)->windowDays())->toBe(7)
        ->and(SavingsGoal::maxPauseDays())->toBe(60)
        ->and(AutomaticDebit::retryAfterHours())->toBe(24)
        ->and(AutomaticDebit::maxFailures())->toBe(2);
});

it('changes the return window that is actually enforced', function () {
    Setting::set('returns.window_days', 14, 'operations');

    expect(app(ReturnPolicy::class)->windowDays())->toBe(14);
});

it('changes how long a plan may stay paused', function () {
    Setting::set('savings.max_pause_days', 30, 'operations');

    expect(SavingsGoal::maxPauseDays())->toBe(30);
});

it('changes the automatic debit retry behaviour', function () {
    Setting::set('automatic_debit.retry_after_hours', 48, 'operations');
    Setting::set('automatic_debit.max_failures', 3, 'operations');

    expect(AutomaticDebit::retryAfterHours())->toBe(48)
        ->and(AutomaticDebit::maxFailures())->toBe(3);
});

it('saves the whole form and reads it back', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/settings/operations'), [
            'return_window_days' => 21,
            'refund_days_min' => 3,
            'refund_days_max' => 7,
            'max_pause_days' => 45,
            'debit_retry_after_hours' => 36,
            'debit_max_failures' => 3,
        ])
        ->assertRedirect();

    Setting::flushCache();

    expect(app(ReturnPolicy::class)->windowDays())->toBe(21)
        ->and(app(ReturnPolicy::class)->refundDaysMin())->toBe(3)
        ->and(app(ReturnPolicy::class)->refundDaysMax())->toBe(7)
        ->and(SavingsGoal::maxPauseDays())->toBe(45);
});

it('refuses a refund window that ends before it starts', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/settings/operations'), [
            'return_window_days' => 7,
            'refund_days_min' => 10,
            'refund_days_max' => 5,
            'max_pause_days' => 60,
            'debit_retry_after_hours' => 24,
            'debit_max_failures' => 2,
        ])
        ->assertSessionHasErrors('refund_days_max');
});

it('refuses an absurd return window rather than trusting a typo', function () {
    $this->actingAs($this->admin)
        ->post(adminUrl('/settings/operations'), [
            'return_window_days' => 3650,
            'refund_days_min' => 5,
            'refund_days_max' => 10,
            'max_pause_days' => 60,
            'debit_retry_after_hours' => 24,
            'debit_max_failures' => 2,
        ])
        ->assertSessionHasErrors('return_window_days');
});

it('keeps the settings screen away from staff without settings.manage', function () {
    $agent = User::factory()->create(['user_type' => UserType::Staff]);
    $agent->forceFill(['two_factor_confirmed_at' => now()])->save();
    $agent->assignRole('Support Agent');

    $this->actingAs($agent)
        ->get(adminUrl('/settings/operations'))
        ->assertForbidden();
});
