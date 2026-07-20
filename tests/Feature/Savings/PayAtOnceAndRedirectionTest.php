<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Events\PlanReadyForDelivery;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Savings\Services\OpenSavingsService;
use App\Modules\Savings\Services\PlanService;
use App\Modules\Savings\Services\RedirectionService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\IdentityStatus;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\PlanPaymentMode;
use App\Shared\Enums\PlanStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 5 QA: Pay At Once reaches Ready for Delivery immediately after full
 * verified payment; redirection carries the full balance, re-locks prices,
 * is blocked after Ready for Delivery, and never produces cash.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create([
        'user_id' => $this->customer->id,
        'identity_status' => IdentityStatus::Verified,
    ]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 150_000_00]);
});

function topUp(User $user, int $amountKobo): void
{
    app(WalletService::class)->creditDeposit($user, $amountKobo, 'TEST-DEP-'.fake()->unique()->uuid());
}

it('completes a Pay At Once purchase straight to Ready for Delivery', function () {
    Event::fake([PlanReadyForDelivery::class]);
    topUp($this->customer, 200_000_00);

    $this->actingAs($this->customer)
        ->post(route('checkout.pay-at-once.store', $this->product->slug))
        ->assertRedirect();

    $plan = ProductTargetPlan::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($plan->payment_mode)->toBe(PlanPaymentMode::PayAtOnce)
        ->and($plan->status)->toBe(PlanStatus::ReadyForDelivery)
        ->and($plan->amount_saved_kobo)->toBe(150_000_00)
        ->and($plan->remaining_balance_kobo)->toBe(0);

    // Full price debited from the wallet in one ledger row.
    expect(app(WalletService::class)->getOrCreate($this->customer)->balance_kobo)->toBe(50_000_00);

    Event::assertDispatched(PlanReadyForDelivery::class);
});

it('does not require BVN/NIN for Pay At Once (it is a normal purchase)', function () {
    $unverified = User::factory()->create(['phone_verified_at' => now()]);
    $unverified->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $unverified->id]);
    topUp($unverified, 150_000_00);

    $this->actingAs($unverified)
        ->post(route('checkout.pay-at-once.store', $this->product->slug))
        ->assertRedirect();

    expect(ProductTargetPlan::query()->where('user_id', $unverified->id)->firstOrFail()->status)
        ->toBe(PlanStatus::ReadyForDelivery);
});

it('refuses Pay At Once when the wallet cannot cover the price', function () {
    topUp($this->customer, 10_000_00);

    $this->actingAs($this->customer)
        ->from(route('checkout.pay-at-once', $this->product->slug))
        ->post(route('checkout.pay-at-once.store', $this->product->slug))
        ->assertRedirect(route('checkout.pay-at-once', $this->product->slug))
        ->assertSessionHasErrors('amount');

    expect(ProductTargetPlan::query()->where('user_id', $this->customer->id)->exists())->toBeFalse()
        // Nothing was debited.
        ->and(app(WalletService::class)->getOrCreate($this->customer)->balance_kobo)->toBe(10_000_00);
});

it('hides Pay At Once checkout for non-approved products', function () {
    $draft = Product::factory()->create();

    $this->actingAs($this->customer)
        ->get(route('checkout.pay-at-once', $draft->slug))
        ->assertNotFound();
});

it('redirects the full Open Savings balance into a plan and records it', function () {
    topUp($this->customer, 100_000_00);
    app(OpenSavingsService::class)->allocateFromWallet($this->customer, 80_000_00);

    $plan = app(PlanService::class)->create(
        $this->customer, $this->product, PlanPaymentMode::Schedule, PlanCadence::Weekly, 10_000_00,
    );

    $this->actingAs($this->customer)
        ->post(route('savings.plans.redirect-open-savings', $plan->uuid))
        ->assertRedirect();

    $plan->refresh();
    $pot = app(OpenSavingsService::class)->getOrCreate($this->customer);

    expect($plan->amount_saved_kobo)->toBe(80_000_00)
        ->and($pot->balance_kobo)->toBe(0);

    $this->assertDatabaseHas('plan_redirections', [
        'user_id' => $this->customer->id,
        'source_type' => 'open_savings',
        'target_plan_id' => $plan->id,
        'balance_transferred_kobo' => 80_000_00,
    ]);

    $this->assertDatabaseHas('plan_contributions', [
        'plan_id' => $plan->id,
        'source' => 'redirection',
        'amount_kobo' => 80_000_00,
    ]);
});

it('caps an Open Savings redirection at the remaining target and reaches Ready for Delivery', function () {
    topUp($this->customer, 300_000_00);
    app(OpenSavingsService::class)->allocateFromWallet($this->customer, 250_000_00);

    $plan = app(PlanService::class)->create(
        $this->customer, $this->product, PlanPaymentMode::Schedule, PlanCadence::Weekly, 10_000_00,
    );

    app(RedirectionService::class)->redirectOpenSavings($this->customer, $plan);

    $plan->refresh();
    $pot = app(OpenSavingsService::class)->getOrCreate($this->customer);

    // Only ₦150k (the target) moved; the surplus ₦100k stays in the pot.
    expect($plan->status)->toBe(PlanStatus::ReadyForDelivery)
        ->and($plan->amount_saved_kobo)->toBe(150_000_00)
        ->and($pot->balance_kobo)->toBe(100_000_00);
});

it('switches an active plan to a new product carrying the full balance at a fresh locked price', function () {
    topUp($this->customer, 60_000_00);
    $plan = app(PlanService::class)->create(
        $this->customer, $this->product, PlanPaymentMode::Schedule, PlanCadence::Weekly, 10_000_00,
    );
    app(PlanService::class)->contributeFromWallet($this->customer, $plan, 60_000_00);

    $newProduct = Product::factory()->approved()->create(['price_kobo' => 90_000_00]);

    $this->actingAs($this->customer)
        ->post(route('savings.plans.switch-product', $plan->uuid), ['product_uuid' => $newProduct->uuid])
        ->assertRedirect();

    $plan->refresh();

    expect($plan->product_id)->toBe($newProduct->id)
        ->and($plan->target_price_kobo)->toBe(90_000_00)
        ->and($plan->amount_saved_kobo)->toBe(60_000_00)
        ->and($plan->remaining_balance_kobo)->toBe(30_000_00);

    $this->assertDatabaseHas('plan_redirections', [
        'source_type' => 'plan',
        'source_id' => $plan->id,
        'old_target_price_kobo' => 150_000_00,
        'new_target_price_kobo' => 90_000_00,
        'balance_transferred_kobo' => 60_000_00,
    ]);
});

it('reaches Ready for Delivery immediately when the carried balance covers the cheaper product', function () {
    topUp($this->customer, 100_000_00);
    $plan = app(PlanService::class)->create(
        $this->customer, $this->product, PlanPaymentMode::Schedule, PlanCadence::Weekly, 10_000_00,
    );
    app(PlanService::class)->contributeFromWallet($this->customer, $plan, 100_000_00);

    $cheaper = Product::factory()->approved()->create(['price_kobo' => 80_000_00]);

    app(RedirectionService::class)->switchProduct($this->customer, $plan, $cheaper);

    expect($plan->refresh()->status)->toBe(PlanStatus::ReadyForDelivery);
});

it('blocks any redirection once the plan is Ready for Delivery', function () {
    topUp($this->customer, 200_000_00);
    app(OpenSavingsService::class)->allocateFromWallet($this->customer, 20_000_00);

    $plan = app(PlanService::class)->payAtOnce($this->customer, $this->product);
    expect($plan->status)->toBe(PlanStatus::ReadyForDelivery);

    $newProduct = Product::factory()->approved()->create();

    expect(fn () => app(RedirectionService::class)->switchProduct($this->customer, $plan, $newProduct))
        ->toThrow(ValidationException::class);

    expect(fn () => app(RedirectionService::class)->redirectOpenSavings($this->customer, $plan))
        ->toThrow(ValidationException::class);
});
