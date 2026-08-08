<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Changing the schedule on a running plan.
 *
 * The item and its frozen price never move — only the rhythm — so this is
 * deliberately a separate, narrower operation than switching the item.
 * Extending is capped because it stretches how long a locked price is held
 * against inflation; paying faster is always allowed.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 120_000_00]);
});

function scheduleTerm(
    int $months,
    PlanCadence $cadence = PlanCadence::Monthly,
    int $minTargetKobo = 0,
    bool $active = true,
): PlanTerm {
    return PlanTerm::query()->create([
        'name' => $cadence->value.' over '.$months,
        'cadence' => $cadence,
        'duration_months' => $months,
        'min_target_kobo' => $minTargetKobo,
        'first_payment_due_days' => 30,
        'missed_payments_allowed' => 3,
        'is_active' => $active,
    ]);
}

function schedulePlan(User $customer, Product $product, PlanTerm $term): SavingsGoal
{
    return app(SavingsGoalService::class)->createFromLines(
        $customer,
        collect([['cartItemId' => null, 'product' => $product, 'quantity' => 1]]),
        ['delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa'],
        $term,
    );
}

// ── The maths ───────────────────────────────────────────────────────────

it('spreads only what is left, not the whole target again', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 60_000_00, reference: 'PAY-1');

    // ₦60,000 left over 6 monthly payments, not the original ₦120,000.
    $goal = app(SavingsGoalService::class)
        ->reschedule($this->customer, $goal->refresh(), scheduleTerm(6));

    expect($goal->installment_kobo)->toBe((int) ceil((planTarget(120_000_00) - 60_000_00) / 6))
        ->and($goal->target_kobo)->toBe(planTarget(120_000_00))
        ->and($goal->paid_kobo)->toBe(60_000_00);
});

it('keeps the payment count honest across the change', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 40_000_00, reference: 'PAY-1');

    $goal = app(SavingsGoalService::class)
        ->reschedule($this->customer, $goal->refresh(), scheduleTerm(6));

    // One made, six to go — "1 of 7", not a count invented by dividing the
    // money already banked by the new smaller instalment.
    expect($goal->installmentsPaid())->toBe(1)
        ->and($goal->installments)->toBe(7);
});

it('leaves the item and its locked price alone', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    $lockedBefore = $goal->items->first()->unit_price_kobo;

    $this->product->update(['price_kobo' => 200_000_00]);

    $goal = app(SavingsGoalService::class)
        ->reschedule($this->customer, $goal->refresh(), scheduleTerm(6));

    expect($goal->target_kobo)->toBe(planTarget(120_000_00))
        ->and($goal->items()->first()->unit_price_kobo)->toBe($lockedBefore);
});

it('moves the cadence as well as the count', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));

    $goal = app(SavingsGoalService::class)
        ->reschedule($this->customer, $goal, scheduleTerm(3, PlanCadence::Weekly));

    expect($goal->cadence)->toBe(PlanCadence::Weekly)
        ->and($goal->installments)->toBe(12);
});

// ── Extending is capped ─────────────────────────────────────────────────

it('allows one extension', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));

    $goal = app(SavingsGoalService::class)->reschedule($this->customer, $goal, scheduleTerm(6));

    expect($goal->extension_count)->toBe(1);
});

it('refuses a second extension', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    $goal = app(SavingsGoalService::class)->reschedule($this->customer, $goal, scheduleTerm(6));

    app(SavingsGoalService::class)->reschedule($this->customer, $goal, scheduleTerm(9));
})->throws(ValidationException::class);

it('always allows shortening, even after an extension', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(6));
    $goal = app(SavingsGoalService::class)->reschedule($this->customer, $goal, scheduleTerm(12));

    // Going shorter is the customer paying faster, which never needs capping.
    $goal = app(SavingsGoalService::class)->reschedule($this->customer, $goal, scheduleTerm(3));

    expect($goal->duration_months)->toBe(3)
        ->and($goal->extension_count)->toBe(1);
});

it('refuses to extend a plan that is behind', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    $goal->forceFill(['next_due_at' => now()->subMonths(2)])->save();

    app(SavingsGoalService::class)->reschedule($this->customer, $goal->refresh(), scheduleTerm(6));
})->throws(ValidationException::class);

it('refuses to extend beyond the longest term offered', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    // Offered nowhere: inactive, so it does not count as the longest.
    $tooLong = scheduleTerm(24, active: false);

    app(SavingsGoalService::class)->reschedule($this->customer, $goal, $tooLong);
})->throws(ValidationException::class);

// ── Guards ──────────────────────────────────────────────────────────────

it('refuses a term the order total does not clear', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));

    app(SavingsGoalService::class)
        ->reschedule($this->customer, $goal, scheduleTerm(6, minTargetKobo: 500_000_00));
})->throws(ValidationException::class);

it('refuses to reschedule somebody else plan', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    $stranger = User::factory()->create();

    app(SavingsGoalService::class)->reschedule($stranger, $goal, scheduleTerm(6));
})->throws(ValidationException::class);

it('refuses to reschedule a settled plan', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    $goal->forceFill(['status' => SavingsGoalStatus::Cancelled])->save();

    app(SavingsGoalService::class)->reschedule($this->customer, $goal->refresh(), scheduleTerm(6));
})->throws(ValidationException::class);

it('refuses to reschedule a plan with nothing left to pay', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    app(SavingsGoalService::class)
        ->recordPayment($this->customer, $goal, planTarget(120_000_00), reference: 'PAY-ALL');

    app(SavingsGoalService::class)->reschedule($this->customer, $goal->refresh(), scheduleTerm(6));
})->throws(ValidationException::class);

it('clears a dormancy warning, since the schedule just reset', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(6));
    $goal->forceFill(['dormancy_warned_at' => now()])->save();

    // Shortening, so the arrears guard does not apply.
    $goal = app(SavingsGoalService::class)->reschedule($this->customer, $goal->refresh(), scheduleTerm(3));

    expect($goal->dormancy_warned_at)->toBeNull();
});

// ── Over HTTP ───────────────────────────────────────────────────────────

it('reschedules from the plan page', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    $longer = scheduleTerm(6);

    $this->actingAs($this->customer)
        ->post(route('savings.goals.reschedule', $goal->uuid), ['plan_term_id' => $longer->id])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect($goal->refresh()->duration_months)->toBe(6);
});

it('will not reschedule another customer plan over HTTP', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    $stranger = User::factory()->create();
    $stranger->assignRole('Customer');

    $this->actingAs($stranger)
        ->post(route('savings.goals.reschedule', $goal->uuid), ['plan_term_id' => scheduleTerm(6)->id])
        ->assertForbidden();
});

it('refuses a term that is switched off', function () {
    $goal = schedulePlan($this->customer, $this->product, scheduleTerm(3));
    $retired = scheduleTerm(6, active: false);

    $this->actingAs($this->customer)
        ->post(route('savings.goals.reschedule', $goal->uuid), ['plan_term_id' => $retired->id])
        ->assertSessionHasErrors('plan_term_id');
});
