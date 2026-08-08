<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Commands\RevokeUnpaidPlans;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Notifications\PlanRevokedNotification;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Modules\Savings\Services\SavingsService;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakePaymentGateway;

/**
 * When the first instalment on a plan falls due, and what happens when it
 * never arrives.
 *
 * Admins set this per term: zero days means pay at checkout, anything higher
 * is a grace window. Miss the window and the plan is revoked — anything paid
 * becomes credit toward another product, never cash back.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 152_000_00]);
});

/**
 * A monthly term. Cadence + duration is uniquely indexed, so a test that
 * needs two terms has to give them different durations.
 */
function timedTerm(int $dueDays, int $months = 3): PlanTerm
{
    return PlanTerm::query()->create([
        'name' => 'Monthly over '.$months,
        'cadence' => PlanCadence::Monthly,
        'duration_months' => $months,
        'min_target_kobo' => 0,
        'first_payment_due_days' => $dueDays,
        'is_active' => true,
    ]);
}

/**
 * Staff, not just an Administrator role, and already enrolled in 2FA — the
 * admin portal checks all three, and an unenrolled staff member is redirected
 * to set it up rather than shown a validation error.
 */
function planTermAdmin(): User
{
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $admin;
}

function planTermsUrl(): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/settings/plan-terms';
}

/** @return array<string, mixed> */
function planCheckout(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Musa Ibrahim',
        'recipient_phone' => '08031234567',
        'delivery_address' => '12 Marina Road',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'payment_method' => 'pay_small_small',
    ], $overrides);
}

function startPlan(User $customer, Product $product, PlanTerm $term): SavingsGoal
{
    return app(SavingsGoalService::class)->createFromLines(
        $customer,
        collect([['cartItemId' => null, 'product' => $product, 'quantity' => 1]]),
        ['delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa'],
        $term,
    );
}

// ── The term ────────────────────────────────────────────────────────────

it('treats zero days as paying at checkout', function () {
    expect(timedTerm(0, 3)->paysUpfront())->toBeTrue()
        ->and(timedTerm(5, 6)->paysUpfront())->toBeFalse();
});

it('describes the deadline in plain English', function () {
    expect(timedTerm(0, 3)->firstPaymentLabel())->toBe('First payment today')
        ->and(timedTerm(1, 6)->firstPaymentLabel())->toBe('First payment within 1 day')
        ->and(timedTerm(5, 9)->firstPaymentLabel())->toBe('First payment within 5 days');
});

it('gives an up-front plan a day to settle, not zero', function () {
    // The plan exists before the customer reaches Paystack, so judging it
    // unpaid immediately would revoke every checkout in flight.
    $goal = startPlan($this->customer, $this->product, timedTerm(0));

    expect($goal->first_payment_due_at->isFuture())->toBeTrue()
        ->and((int) now()->diffInHours($goal->first_payment_due_at))->toBeGreaterThanOrEqual(23);
});

it('stamps the grace window on the plan', function () {
    $goal = startPlan($this->customer, $this->product, timedTerm(5));

    expect((int) round($goal->first_payment_due_at->diffInDays(now(), absolute: true)))->toBe(5);
});

it('keeps the deadline a plan started on when the term is edited later', function () {
    $term = timedTerm(5);
    $goal = startPlan($this->customer, $this->product, $term);
    $agreed = $goal->first_payment_due_at;

    $term->update(['first_payment_due_days' => 30]);

    expect($goal->refresh()->first_payment_due_at->timestamp)->toBe($agreed->timestamp);
});

// ── Payment clears the deadline ─────────────────────────────────────────

it('clears the deadline once a payment lands', function () {
    $goal = startPlan($this->customer, $this->product, timedTerm(5));

    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 50_000_00, reference: 'PAY-1');

    expect($goal->refresh()->first_payment_due_at)->toBeNull();
});

// ── Revocation ──────────────────────────────────────────────────────────

it('revokes a plan whose first payment never arrived', function () {
    $goal = startPlan($this->customer, $this->product, timedTerm(5));
    $goal->forceFill(['first_payment_due_at' => now()->subDay()])->save();

    $this->artisan(RevokeUnpaidPlans::class)->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Cancelled)
        ->and($goal->first_payment_due_at)->toBeNull();
});

it('leaves a plan alone before its deadline', function () {
    $goal = startPlan($this->customer, $this->product, timedTerm(5));

    $this->artisan(RevokeUnpaidPlans::class)->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Saving);
});

it('carries anything already paid over as credit, never cash', function () {
    $goal = startPlan($this->customer, $this->product, timedTerm(5));
    // Part-paid, then stalled: the deadline is re-armed to simulate a plan
    // that went quiet after one payment.
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 20_000_00, reference: 'PAY-PART');
    $goal->refresh()->forceFill(['first_payment_due_at' => now()->subDay()])->save();

    $this->artisan(RevokeUnpaidPlans::class)->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Cancelled)
        ->and(app(SavingsService::class)->creditKobo($this->customer))->toBe(20_000_00);
});

it('tells the customer their plan was revoked', function () {
    $goal = startPlan($this->customer, $this->product, timedTerm(5));
    $goal->forceFill(['first_payment_due_at' => now()->subDay()])->save();

    $this->artisan(RevokeUnpaidPlans::class)->assertSuccessful();

    Notification::assertSentTo($this->customer, PlanRevokedNotification::class);
});

it('is safe to run twice', function () {
    $goal = startPlan($this->customer, $this->product, timedTerm(5));
    $goal->forceFill(['first_payment_due_at' => now()->subDay()])->save();

    $this->artisan(RevokeUnpaidPlans::class)->assertSuccessful();
    $this->artisan(RevokeUnpaidPlans::class)->assertSuccessful();

    // Credit banked once, not once per run.
    expect(app(SavingsService::class)->creditKobo($this->customer))->toBe(0);
    Notification::assertSentToTimes($this->customer, PlanRevokedNotification::class, 1);
});

it('changes nothing on a dry run', function () {
    $goal = startPlan($this->customer, $this->product, timedTerm(5));
    $goal->forceFill(['first_payment_due_at' => now()->subDay()])->save();

    $this->artisan(RevokeUnpaidPlans::class, ['--dry-run' => true])->assertSuccessful();

    expect($goal->refresh()->status)->toBe(SavingsGoalStatus::Saving);
    Notification::assertNothingSent();
});

// ── Checkout ────────────────────────────────────────────────────────────

it('sends an up-front checkout to Paystack for the instalments chosen', function () {
    $gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $gateway);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), planCheckout([
            'plan_term_id' => timedTerm(0)->id,
            'upfront_installments' => 2,
        ]))
        ->assertSessionDoesntHaveErrors();

    // ₦152,000 over three rounds up to ₦50,666.67 each, so two of them is
    // ₦101,333.34 — the last instalment absorbs the rounding, not the first.
    $goal = SavingsGoal::query()->where('user_id', $this->customer->id)->firstOrFail();

    // A third of the whole target, delivery included — a plan carries the
    // delivery fee now, so it is spread across the instalments too.
    $instalment = (int) ceil(planTarget(152_000_00) / 3);

    expect($goal->installment_kobo)->toBe($instalment)
        ->and($gateway->lastAmountKobo())->toBe($instalment * 2);
});

it('charges only one instalment by default', function () {
    $gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $gateway);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), planCheckout(['plan_term_id' => timedTerm(0)->id]))
        ->assertSessionDoesntHaveErrors();

    expect($gateway->lastAmountKobo())->toBe((int) ceil(planTarget(152_000_00) / 3));
});

it('never charges more than the plan is worth', function () {
    $gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $gateway);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    // Three instalments rounded up overshoot the target by a few kobo.
    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), planCheckout([
            'plan_term_id' => timedTerm(0)->id,
            'upfront_installments' => 3,
        ]))
        ->assertSessionDoesntHaveErrors();

    // Capped at the target, never a kobo over it.
    expect($gateway->lastAmountKobo())->toBe(planTarget(152_000_00));
});

it('lands a grace checkout on the plan page with nothing charged', function () {
    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), planCheckout(['plan_term_id' => timedTerm(5)->id]))
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    $goal = SavingsGoal::query()->where('user_id', $this->customer->id)->firstOrFail();
    expect($goal->paid_kobo)->toBe(0)
        ->and($goal->first_payment_due_at)->not->toBeNull();
});

it('tells checkout which terms charge up front', function () {
    timedTerm(0, 3);
    timedTerm(7, 6);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    $this->actingAs($this->customer)
        ->get(route('cart.checkout'))
        ->assertInertia(fn ($page) => $page
            ->where('planTerms.0.paysUpfront', true)
            ->where('planTerms.0.firstPaymentDueDays', 0)
            ->where('planTerms.1.paysUpfront', false)
            ->where('planTerms.1.firstPaymentDueDays', 7));
});

it('never settles more instalments than the term has', function () {
    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $this->product->uuid, 'quantity' => 1]);

    // Asking for 99 of 3 must charge the plan in full, not 99 instalments.
    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), planCheckout([
            'plan_term_id' => timedTerm(0)->id,
            'upfront_installments' => 99,
        ]))
        ->assertSessionDoesntHaveErrors();

    $goal = SavingsGoal::query()->where('user_id', $this->customer->id)->firstOrFail();
    expect($goal->target_kobo)->toBe(planTarget(152_000_00));
});

// ── Admin ───────────────────────────────────────────────────────────────

it('lets an admin set the deadline on a term', function () {
    $this->actingAs(planTermAdmin())
        ->post(planTermsUrl(), [
            'cadence' => 'monthly',
            'duration_months' => 3,
            'first_payment_due_days' => 5,
            'is_active' => true,
        ])
        ->assertSessionDoesntHaveErrors();

    expect(PlanTerm::query()->latest('id')->first()->first_payment_due_days)->toBe(5);
});

it('defaults a term to charging at checkout', function () {
    $this->actingAs(planTermAdmin())
        ->post(planTermsUrl(), [
            'cadence' => 'monthly',
            'duration_months' => 3,
            'is_active' => true,
        ])
        ->assertSessionDoesntHaveErrors();

    expect(PlanTerm::query()->latest('id')->first()->first_payment_due_days)->toBe(0);
});

it('refuses a deadline longer than ninety days', function () {
    $this->actingAs(planTermAdmin())
        ->post(planTermsUrl(), [
            'cadence' => 'monthly',
            'duration_months' => 3,
            'first_payment_due_days' => 120,
            'is_active' => true,
        ])
        ->assertSessionHasErrors('first_payment_due_days');
});
