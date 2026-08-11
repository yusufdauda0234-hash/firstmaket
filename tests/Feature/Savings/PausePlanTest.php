<?php

use App\Models\User;
use App\Modules\Savings\Commands\SweepDormantPlans;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2B: pausing a Pay Small Small plan.
 *
 * The rule the whole feature turns on: a pause stops the *chasing* — the
 * reminders and the automatic debit — and nothing else. The frozen price, the
 * money already paid and the plan's status all carry on untouched.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->customer = User::factory()->create();
    $this->goals = app(SavingsGoalService::class);
});

function pausablePlan(User $owner, array $overrides = []): SavingsGoal
{
    return SavingsGoal::query()->create(array_merge([
        'user_id' => $owner->id,
        'target_kobo' => 500_000,
        'delivery_fee_kobo' => 0,
        'cadence' => PlanCadence::Monthly,
        'installments' => 5,
        'payments_made' => 1,
        'installment_kobo' => 100_000,
        'paid_kobo' => 100_000,
        'status' => SavingsGoalStatus::Saving,
        'next_due_at' => now()->addMonth(),
        'started_at' => now()->subMonth(),
        'missed_payments_allowed' => 2,
    ], $overrides));
}

it('pauses without touching the price, the money or the status', function () {
    $plan = pausablePlan($this->customer);

    $this->goals->pause($this->customer, $plan);
    $plan->refresh();

    expect($plan->paused_at)->not->toBeNull()
        ->and($plan->isPaused())->toBeTrue()
        // The three things a pause must never move.
        ->and($plan->target_kobo)->toBe(500_000)
        ->and($plan->paid_kobo)->toBe(100_000)
        ->and($plan->status)->toBe(SavingsGoalStatus::Saving);
});

it('resumes and leaves the plan exactly as it was', function () {
    $plan = pausablePlan($this->customer);

    $this->goals->pause($this->customer, $plan);
    $this->goals->resume($this->customer, $plan);
    $plan->refresh();

    expect($plan->paused_at)->toBeNull()
        ->and($plan->isPaused())->toBeFalse()
        ->and($plan->target_kobo)->toBe(500_000)
        ->and($plan->paid_kobo)->toBe(100_000);
});

it('refuses to pause a plan whose first payment has not arrived', function () {
    // Otherwise anyone could lock today's price for free and simply hold it.
    $plan = pausablePlan($this->customer, ['payments_made' => 0, 'paid_kobo' => 0]);

    expect(fn () => $this->goals->pause($this->customer, $plan))
        ->toThrow(ValidationException::class);

    expect($plan->refresh()->paused_at)->toBeNull();
});

it('will not let one customer pause another customer plan', function () {
    $plan = pausablePlan($this->customer);
    $stranger = User::factory()->create();

    expect(fn () => $this->goals->pause($stranger, $plan))
        ->toThrow(ValidationException::class);

    expect($plan->refresh()->paused_at)->toBeNull();
});

it('is idempotent, so a double tap does not restart the window', function () {
    $plan = pausablePlan($this->customer);

    $this->goals->pause($this->customer, $plan);
    $firstPausedAt = $plan->refresh()->paused_at;

    $this->travel(2)->days();
    $this->goals->pause($this->customer, $plan);

    expect($plan->refresh()->paused_at->timestamp)->toBe($firstPausedAt->timestamp);
});

it('keeps the dormancy sweep away from a paused plan', function () {
    // Far enough behind to be swept, but paused.
    $plan = pausablePlan($this->customer, [
        'next_due_at' => now()->subMonths(6),
        'missed_payments_allowed' => 1,
    ]);

    $this->goals->pause($this->customer, $plan);

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();

    expect($plan->refresh()->dormancy_warned_at)->toBeNull()
        ->and($plan->status)->toBe(SavingsGoalStatus::Saving);
});

it('lets the sweep resume once the pause window has run out', function () {
    $plan = pausablePlan($this->customer, [
        'next_due_at' => now()->subMonths(6),
        'missed_payments_allowed' => 1,
    ]);

    $this->goals->pause($this->customer, $plan);

    // A pause cannot hold a frozen price forever.
    $this->travel(config('firstmaket.savings.max_pause_days') + 1)->days();

    expect($plan->refresh()->isPaused())->toBeFalse()
        ->and($plan->pauseHasExpired())->toBeTrue();

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();

    expect($plan->refresh()->dormancy_warned_at)->not->toBeNull();
});

it('clears an outstanding dormancy warning, so pausing is a real answer to it', function () {
    $plan = pausablePlan($this->customer, ['dormancy_warned_at' => now()->subDay()]);

    $this->goals->pause($this->customer, $plan);

    expect($plan->refresh()->dormancy_warned_at)->toBeNull();
});

it('exposes pause controls over HTTP and blocks somebody else from using them', function () {
    $plan = pausablePlan($this->customer);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/savings/plans/{$plan->uuid}/pause")
        ->assertForbidden();

    $this->actingAs($this->customer)
        ->post("/savings/plans/{$plan->uuid}/pause")
        ->assertRedirect();

    expect($plan->refresh()->isPaused())->toBeTrue();

    $this->actingAs($this->customer)
        ->post("/savings/plans/{$plan->uuid}/resume")
        ->assertRedirect();

    expect($plan->refresh()->isPaused())->toBeFalse();
});
