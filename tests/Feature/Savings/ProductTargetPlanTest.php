<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Events\PlanReadyForDelivery;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Savings\Services\OpenSavingsService;
use App\Modules\Savings\Services\PlanService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\IdentityStatus;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\PlanPaymentMode;
use App\Shared\Enums\PlanStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 5 QA: locked target price, contribution math, progress → Ready for
 * Delivery, identity gating, and pause/resume.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create([
        'user_id' => $this->customer->id,
        'identity_status' => IdentityStatus::Verified,
    ]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 200_000_00]);
});

function fundCustomerWallet(User $user, int $amountKobo): void
{
    app(WalletService::class)->creditDeposit($user, $amountKobo, 'TEST-DEP-'.fake()->unique()->uuid());
}

function startSchedulePlan(User $user, Product $product, ?int $suggestedKobo = 20_000_00): ProductTargetPlan
{
    return app(PlanService::class)->create(
        user: $user,
        product: $product,
        mode: PlanPaymentMode::Schedule,
        cadence: PlanCadence::Weekly,
        suggestedContributionKobo: $suggestedKobo,
    );
}

it('blocks schedule plans until BVN/NIN identity is verified', function () {
    $unverified = User::factory()->create(['phone_verified_at' => now()]);
    $unverified->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $unverified->id]);

    startSchedulePlan($unverified, $this->product);
})->throws(ValidationException::class);

it('refuses to start a plan on a non-approved product', function () {
    $pending = Product::factory()->pending()->create();

    startSchedulePlan($this->customer, $pending);
})->throws(ValidationException::class);

it('locks the target price at creation even when the vendor later changes the price', function () {
    $plan = startSchedulePlan($this->customer, $this->product);

    expect($plan->target_price_kobo)->toBe(200_000_00);

    // Vendor price change never touches the running plan.
    $this->product->forceFill(['price_kobo' => 350_000_00])->save();

    expect($plan->refresh()->target_price_kobo)->toBe(200_000_00)
        ->and($plan->remaining_balance_kobo)->toBe(200_000_00);
});

it('applies wallet contributions with correct math and ledger linkage', function () {
    fundCustomerWallet($this->customer, 100_000_00);
    $plan = startSchedulePlan($this->customer, $this->product);

    $this->actingAs($this->customer)
        ->post(route('savings.plans.contribute', $plan->uuid), [
            'amount_naira' => 50_000,
            'source' => 'wallet',
        ])
        ->assertRedirect();

    $plan->refresh();

    expect($plan->amount_saved_kobo)->toBe(50_000_00)
        ->and($plan->remaining_balance_kobo)->toBe(150_000_00)
        ->and((float) $plan->progress_percentage)->toBe(25.0)
        ->and($plan->status)->toBe(PlanStatus::Active);

    $this->assertDatabaseHas('wallet_transactions', [
        'user_id' => $this->customer->id,
        'type' => 'plan_contribution',
        'direction' => 'debit',
        'amount_kobo' => 50_000_00,
    ]);

    $this->assertDatabaseHas('plan_contributions', [
        'plan_id' => $plan->id,
        'amount_kobo' => 50_000_00,
        'source' => 'paystack_deposit',
    ]);
});

it('applies Open Savings contributions without touching the wallet balance', function () {
    fundCustomerWallet($this->customer, 100_000_00);
    app(OpenSavingsService::class)->allocateFromWallet($this->customer, 60_000_00);
    $plan = startSchedulePlan($this->customer, $this->product);

    app(PlanService::class)->contributeFromOpenSavings($this->customer, $plan, 30_000_00);

    $plan->refresh();
    $walletBalance = app(WalletService::class)->getOrCreate($this->customer)->balance_kobo;
    $potBalance = app(OpenSavingsService::class)->getOrCreate($this->customer)->balance_kobo;

    expect($plan->amount_saved_kobo)->toBe(30_000_00)
        ->and($walletBalance)->toBe(40_000_00) // untouched by the pot→plan move
        ->and($potBalance)->toBe(30_000_00);

    $this->assertDatabaseHas('plan_contributions', [
        'plan_id' => $plan->id,
        'source' => 'open_savings',
        'wallet_transaction_id' => null,
    ]);
});

it('rejects a contribution above the remaining balance', function () {
    fundCustomerWallet($this->customer, 300_000_00);
    $plan = startSchedulePlan($this->customer, $this->product);

    app(PlanService::class)->contributeFromWallet($this->customer, $plan, 250_000_00);
})->throws(ValidationException::class);

it('moves the plan to Ready for Delivery at 100% and fires the domain event', function () {
    Event::fake([PlanReadyForDelivery::class]);
    fundCustomerWallet($this->customer, 200_000_00);
    $plan = startSchedulePlan($this->customer, $this->product);

    app(PlanService::class)->contributeFromWallet($this->customer, $plan, 200_000_00);

    $plan->refresh();

    expect($plan->status)->toBe(PlanStatus::ReadyForDelivery)
        ->and((float) $plan->progress_percentage)->toBe(100.0)
        ->and($plan->remaining_balance_kobo)->toBe(0)
        ->and($plan->ready_for_delivery_at)->not->toBeNull();

    Event::assertDispatched(PlanReadyForDelivery::class, fn (PlanReadyForDelivery $event) => $event->planId === $plan->id
        && $event->targetPriceKobo === 200_000_00);

    $this->assertDatabaseHas('plan_status_events', [
        'plan_id' => $plan->id,
        'old_status' => 'active',
        'new_status' => 'ready_for_delivery',
    ]);
});

it('recalculates expected completion from the average of the last three contributions', function () {
    fundCustomerWallet($this->customer, 150_000_00);
    $plan = startSchedulePlan($this->customer, $this->product, null);
    $service = app(PlanService::class);

    $service->contributeFromWallet($this->customer, $plan, 10_000_00);
    $service->contributeFromWallet($this->customer, $plan, 20_000_00);
    $service->contributeFromWallet($this->customer, $plan, 30_000_00);

    $plan->refresh();

    // Average of last 3 = ₦20k per weekly cycle; remaining ₦140k → 7 cycles.
    expect($plan->expected_completion_date?->toDateString())
        ->toBe(now()->addDays(7 * 7)->toDateString());
});

it('pauses and resumes without touching money or the locked price', function () {
    fundCustomerWallet($this->customer, 50_000_00);
    $plan = startSchedulePlan($this->customer, $this->product);
    app(PlanService::class)->contributeFromWallet($this->customer, $plan, 20_000_00);

    $this->actingAs($this->customer)
        ->post(route('savings.plans.pause', $plan->uuid), ['reason' => 'Travelling'])
        ->assertRedirect();

    $plan->refresh();
    expect($plan->status)->toBe(PlanStatus::Paused)
        ->and($plan->amount_saved_kobo)->toBe(20_000_00)
        ->and($plan->target_price_kobo)->toBe(200_000_00);

    // Contributions are refused while paused.
    $this->actingAs($this->customer)
        ->post(route('savings.plans.contribute', $plan->uuid), ['amount_naira' => 1_000, 'source' => 'wallet'])
        ->assertSessionHasErrors('plan');

    $this->actingAs($this->customer)
        ->post(route('savings.plans.resume', $plan->uuid))
        ->assertRedirect();

    expect($plan->refresh()->status)->toBe(PlanStatus::Active);
});

it('never exposes another customer’s plan', function () {
    $plan = startSchedulePlan($this->customer, $this->product);

    $other = User::factory()->create(['phone_verified_at' => now()]);
    $other->assignRole('Customer');

    $this->actingAs($other)->get(route('savings.plans.show', $plan->uuid))->assertForbidden();
    $this->actingAs($other)
        ->post(route('savings.plans.contribute', $plan->uuid), ['amount_naira' => 1_000, 'source' => 'wallet'])
        ->assertForbidden();
});
