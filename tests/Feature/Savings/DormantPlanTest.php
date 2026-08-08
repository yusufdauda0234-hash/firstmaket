<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Commands\SweepDormantPlans;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Notifications\PlanDormantNotification;
use App\Modules\Savings\Notifications\PlanRevokedNotification;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Modules\Savings\Services\SavingsService;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Plans nobody is paying into.
 *
 * Until now only the first payment had a deadline; after that a plan could
 * sit forever holding a locked price. The allowance is set per term and
 * snapshotted onto the plan, and nobody loses a plan without a warning first.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 120_000_00]);
});

function dormancyTerm(int $allowance, int $months = 3, PlanCadence $cadence = PlanCadence::Monthly): PlanTerm
{
    return PlanTerm::query()->create([
        'name' => 'Term '.$cadence->value.' '.$months,
        'cadence' => $cadence,
        'duration_months' => $months,
        'min_target_kobo' => 0,
        // Grace, so creating a plan does not immediately arm the first-payment
        // sweep and confuse what is being tested here.
        'first_payment_due_days' => 30,
        'missed_payments_allowed' => $allowance,
        'is_active' => true,
    ]);
}

function dormancyPlan(User $customer, Product $product, PlanTerm $term): SavingsGoal
{
    return app(SavingsGoalService::class)->createFromLines(
        $customer,
        collect([['cartItemId' => null, 'product' => $product, 'quantity' => 1]]),
        ['delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa'],
        $term,
    );
}

/** Wind the schedule back so $missed payments have come and gone. */
function missPayments(SavingsGoal $goal, int $missed): SavingsGoal
{
    $due = now();

    for ($i = 1; $i < $missed; $i++) {
        $due = $due->copy()->subMonthNoOverflow();
    }

    $goal->forceFill(['next_due_at' => $due->subDay(), 'first_payment_due_at' => null])->save();

    return $goal->refresh();
}

// ── Counting ────────────────────────────────────────────────────────────

it('counts nothing missed while the schedule is in the future', function () {
    $goal = dormancyPlan($this->customer, $this->product, dormancyTerm(3));

    expect($goal->missedPayments())->toBe(0)
        ->and($goal->isDormant())->toBeFalse();
});

it('counts each elapsed cycle as a missed payment', function () {
    $goal = dormancyPlan($this->customer, $this->product, dormancyTerm(3));

    expect(missPayments($goal, 1)->missedPayments())->toBe(1)
        ->and(missPayments($goal, 3)->missedPayments())->toBe(3);
});

it('is dormant only past the allowance, not at it', function () {
    $goal = dormancyPlan($this->customer, $this->product, dormancyTerm(3));

    expect(missPayments($goal, 3)->isDormant())->toBeFalse()
        ->and(missPayments($goal, 4)->isDormant())->toBeTrue();
});

it('never lets go of a plan on a zero allowance', function () {
    $goal = dormancyPlan($this->customer, $this->product, dormancyTerm(0));

    expect(missPayments($goal, 12)->isDormant())->toBeFalse();
});

it('snapshots the allowance so editing the term does not move it', function () {
    $term = dormancyTerm(3);
    $goal = dormancyPlan($this->customer, $this->product, $term);

    $term->update(['missed_payments_allowed' => 12]);

    expect($goal->refresh()->missed_payments_allowed)->toBe(3);
});

// ── Warn, then close ────────────────────────────────────────────────────

it('warns before closing anything', function () {
    $goal = missPayments(dormancyPlan($this->customer, $this->product, dormancyTerm(3)), 4);

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Saving)
        ->and($goal->dormancy_warned_at)->not->toBeNull();

    Notification::assertSentTo($this->customer, PlanDormantNotification::class);
});

it('closes only on a later pass', function () {
    $goal = missPayments(dormancyPlan($this->customer, $this->product, dormancyTerm(3)), 4);

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();
    $this->artisan(SweepDormantPlans::class)->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Cancelled);
    Notification::assertSentTo($this->customer, PlanRevokedNotification::class);
});

it('refuses to close a plan that was never warned', function () {
    // The command branches on the warning itself, so this guards the service
    // directly — any future caller (an admin action, another command) must
    // not be able to close a plan without notice either.
    $goal = missPayments(dormancyPlan($this->customer, $this->product, dormancyTerm(3)), 4);

    expect(app(SavingsGoalService::class)->revokeDormant($goal))->toBe(0)
        ->and($goal->refresh()->status)->toBe(SavingsGoalStatus::Saving);

    Notification::assertNothingSent();
});

it('refuses to close a plan that is back within its allowance', function () {
    $goal = missPayments(dormancyPlan($this->customer, $this->product, dormancyTerm(3)), 4);
    app(SavingsGoalService::class)->warnDormant($goal);

    // Paid up since the warning: still flagged, but no longer dormant.
    $goal->refresh()->forceFill(['next_due_at' => now()->addMonth()])->save();

    expect(app(SavingsGoalService::class)->revokeDormant($goal->refresh()))->toBe(0)
        ->and($goal->refresh()->status)->toBe(SavingsGoalStatus::Saving);
});

it('warns once, not on every run', function () {
    missPayments(dormancyPlan($this->customer, $this->product, dormancyTerm(3)), 4);

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();
    $this->artisan(SweepDormantPlans::class)->assertSuccessful();
    $this->artisan(SweepDormantPlans::class)->assertSuccessful();

    Notification::assertSentToTimes($this->customer, PlanDormantNotification::class, 1);
});

it('carries whatever was paid over as credit, never cash', function () {
    $goal = dormancyPlan($this->customer, $this->product, dormancyTerm(3));
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 20_000_00, reference: 'PAY-1');
    missPayments($goal->refresh(), 4);

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();
    $this->artisan(SweepDormantPlans::class)->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Cancelled)
        ->and(app(SavingsService::class)->creditKobo($this->customer))->toBe(20_000_00);
});

// ── Paying rescues the plan ─────────────────────────────────────────────

it('lets one payment clear a warning', function () {
    $goal = missPayments(dormancyPlan($this->customer, $this->product, dormancyTerm(3)), 4);

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();
    expect($goal->refresh()->dormancy_warned_at)->not->toBeNull();

    // Paying pushes next_due_at forward, so the plan is current again.
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 10_000_00, reference: 'PAY-RESCUE');

    expect($goal->refresh()->dormancy_warned_at)->toBeNull()
        ->and($goal->isDormant())->toBeFalse();

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Saving);
});

// ── Safety ──────────────────────────────────────────────────────────────

it('leaves a settled plan alone', function () {
    $goal = missPayments(dormancyPlan($this->customer, $this->product, dormancyTerm(3)), 4);
    $goal->forceFill(['status' => SavingsGoalStatus::Fulfilled])->save();

    $this->artisan(SweepDormantPlans::class)->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Fulfilled);
    Notification::assertNothingSent();
});

it('changes nothing on a dry run', function () {
    $goal = missPayments(dormancyPlan($this->customer, $this->product, dormancyTerm(3)), 4);

    $this->artisan(SweepDormantPlans::class, ['--dry-run' => true])->assertSuccessful();

    expect($goal->refresh()->dormancy_warned_at)->toBeNull();
    Notification::assertNothingSent();
});

it('lets an admin set the allowance on a term', function () {
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($admin)
        ->post('http://'.strtolower((string) config('app.admin_domain')).'/settings/plan-terms', [
            'cadence' => 'weekly',
            'duration_months' => 2,
            'missed_payments_allowed' => 4,
            'is_active' => true,
        ])
        ->assertSessionDoesntHaveErrors();

    expect(PlanTerm::query()->latest('id')->first()->missed_payments_allowed)->toBe(4);
});
