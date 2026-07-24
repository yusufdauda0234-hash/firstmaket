<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Savings\Services\PlanService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Contracts\PlanEligibilityContract;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\PlanPaymentMode;
use App\Shared\Enums\PlanStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 8 QA: bundling multiple products (possibly different vendors) into
 * one multi-product Product Target Plan. Gated by the rule-based eligibility
 * checker; single-product plans are never gated. A bundle only ever
 * delivers all its products together, never a subset early.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

function fundBundleWallet(User $user, int $amountKobo): void
{
    app(WalletService::class)->creditDeposit($user, $amountKobo, 'TEST-DEP-'.fake()->unique()->uuid());
}

/** Give the customer a Completed single-product plan (satisfies eligibility via follow-through). */
function giveCompletedPlan(User $customer, int $priceKobo = 50_000_00): void
{
    $product = Product::factory()->approved()->create(['price_kobo' => $priceKobo]);
    fundBundleWallet($customer, $priceKobo);

    $plan = app(PlanService::class)->payAtOnce($customer, $product);

    app(OrderService::class)->createFromPlan(
        customer: $customer,
        plan: $plan,
        deliveryAddress: '1 Test Close',
        state: 'Lagos',
        lga: 'Eti-Osa',
    );
}

it('blocks a new account from bundling multiple products into one plan', function () {
    $productA = Product::factory()->approved()->create(['price_kobo' => 20_000_00]);
    $productB = Product::factory()->approved()->create(['price_kobo' => 30_000_00]);

    $items = [
        ['product' => $productA, 'quantity' => 1],
        ['product' => $productB, 'quantity' => 1],
    ];

    expect(fn () => app(PlanService::class)->createMultiProduct($this->customer, $items, PlanCadence::Weekly, 5_000_00))
        ->toThrow(ValidationException::class);

    expect(ProductTargetPlan::query()->where('user_id', $this->customer->id)->exists())->toBeFalse();
});

it('never gates a single-product plan behind bundle eligibility', function () {
    $product = Product::factory()->approved()->create();

    // Brand new account — would fail bundle eligibility — but single-product create() succeeds.
    $plan = app(PlanService::class)->create($this->customer, $product, PlanPaymentMode::Schedule, PlanCadence::Weekly, 5_000_00);

    expect($plan->product_id)->toBe($product->id)
        ->and($plan->isBundle())->toBeFalse();
});

it('bundles products from different vendors into one combined target once eligible', function () {
    $this->customer->forceFill(['created_at' => now()->subDays(40)])->save();
    giveCompletedPlan($this->customer);

    $productA = Product::factory()->approved()->create(['price_kobo' => 20_000_00]);
    $productB = Product::factory()->approved()->create(['price_kobo' => 35_000_00]);
    expect($productA->vendor_id)->not->toBe($productB->vendor_id);

    $items = [
        ['product' => $productA, 'quantity' => 1],
        ['product' => $productB, 'quantity' => 2],
    ];

    $plan = app(PlanService::class)->createMultiProduct($this->customer, $items, PlanCadence::Weekly, 10_000_00);

    expect($plan->isBundle())->toBeTrue()
        ->and($plan->target_price_kobo)->toBe(20_000_00 + 35_000_00 * 2)
        ->and($plan->items)->toHaveCount(2)
        ->and($plan->status)->toBe(PlanStatus::Active);
});

it('reaches Ready for Delivery only once the combined target is fully funded, then creates one order per bundled unit atomically', function () {
    $this->customer->forceFill(['created_at' => now()->subDays(40)])->save();
    giveCompletedPlan($this->customer);

    $productA = Product::factory()->approved()->create(['price_kobo' => 20_000_00]);
    $productB = Product::factory()->approved()->create(['price_kobo' => 30_000_00]);

    $items = [
        ['product' => $productA, 'quantity' => 1],
        ['product' => $productB, 'quantity' => 1],
    ];

    $plan = app(PlanService::class)->createMultiProduct($this->customer, $items, PlanCadence::Weekly, 10_000_00);

    fundBundleWallet($this->customer, 50_000_00);

    // Partial funding never creates an order for whichever product happened to fund first.
    app(PlanService::class)->contributeFromWallet($this->customer, $plan, 20_000_00);
    expect($plan->refresh()->status)->toBe(PlanStatus::Active)
        ->and(Order::query()->where('plan_id', $plan->id)->count())->toBe(0);

    // Reaching 100% moves to Ready for Delivery — still no orders until an address is given.
    app(PlanService::class)->contributeFromWallet($this->customer, $plan, 30_000_00);
    expect($plan->refresh()->status)->toBe(PlanStatus::ReadyForDelivery)
        ->and(Order::query()->where('plan_id', $plan->id)->count())->toBe(0);

    $this->actingAs($this->customer)
        ->post(route('orders.store'), [
            'plan_uuid' => $plan->uuid,
            'delivery_address' => '9 Bundle Street',
            'state' => 'Lagos',
            'lga' => 'Ikeja',
        ])
        ->assertRedirect(route('savings.plans.show', $plan->uuid));

    $orders = Order::query()->where('plan_id', $plan->id)->get();

    expect($orders)->toHaveCount(2)
        ->and($orders->pluck('vendor_id')->unique())->toHaveCount(2)
        ->and($orders->pluck('plan_delivery_group_id')->unique())->toHaveCount(1)
        ->and($orders->every(fn (Order $order) => $order->delivery_address === '9 Bundle Street'))->toBeTrue()
        ->and($orders->every(fn (Order $order) => $order->status->value === 'pending'))->toBeTrue()
        ->and($plan->refresh()->status)->toBe(PlanStatus::Completed);
});

it('blocks bundling fewer than two items', function () {
    $this->customer->forceFill(['created_at' => now()->subDays(40)])->save();
    giveCompletedPlan($this->customer);

    $product = Product::factory()->approved()->create();
    $items = [['product' => $product, 'quantity' => 1]];

    expect(fn () => app(PlanService::class)->createMultiProduct($this->customer, $items, PlanCadence::Weekly))
        ->toThrow(ValidationException::class);
});

it('reports a clear ineligibility reason via the contract without throwing', function () {
    $reason = app(PlanEligibilityContract::class)->reasonIneligible($this->customer);

    expect($reason)->not->toBeNull();
});
