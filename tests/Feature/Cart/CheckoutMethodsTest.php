<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Modules\Savings\Services\SavingsService;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Checkout payment-method selection, the real delivery fee, and Pay Small
 * Small plans.
 *
 * There is no customer balance: Card charges the full total through Paystack,
 * Pay Small Small locks the price and collects it in instalments, and OPay is
 * listed for the layout but refused because no gateway sits behind it.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // installments is derived from duration_months on save — four monthly
    // payments means a four-month run.
    $this->term = PlanTerm::query()->create([
        'name' => 'Monthly over 4 months',
        'cadence' => PlanCadence::Monthly,
        'duration_months' => 4,
        'min_target_kobo' => 0,
        'is_active' => true,
    ]);
});

function checkoutCustomer(): User
{
    $user = User::factory()->create();
    $user->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $user->id]);

    return $user;
}

/** @return array<string, mixed> */
function deliveryFields(): array
{
    return [
        'recipient_name' => 'Yakubu Dauda',
        'recipient_phone' => '08031234567',
        'delivery_address' => '12 Marina Road',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
    ];
}

/** Put one product in the cart and start a Pay Small Small plan on it. */
function planFromCheckout(User $user, Product $product, PlanTerm $term, int $quantity = 1): SavingsGoal
{
    test()->actingAs($user)->post(route('cart.items.store'), [
        'product_uuid' => $product->uuid,
        'quantity' => $quantity,
    ]);

    test()->actingAs($user)->post(route('cart.checkout.store'), [
        ...deliveryFields(),
        'payment_method' => 'pay_small_small',
        'plan_term_id' => $term->id,
    ]);

    return SavingsGoal::query()->where('user_id', $user->id)->firstOrFail();
}

it('renders checkout with the card and plan methods, terms, and a charged delivery fee', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 800_00]);

    $this->actingAs($user)->post(route('cart.items.store'), ['product_uuid' => $product->uuid]);

    $this->actingAs($user)->get(route('cart.checkout'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Cart/Checkout')
            ->has('paymentMethods', 4)
            ->where('paymentMethods.0.value', 'card')
            ->where('paymentMethods.1.value', 'pay_small_small')
            // Listed but not selectable: pay on delivery is off out of the
            // box, and OPay has no credentials in this codebase at all.
            ->where('paymentMethods.2.value', 'pay_on_delivery')
            ->where('paymentMethods.2.available', false)
            ->where('paymentMethods.3.value', 'opay')
            ->where('paymentMethods.3.available', false)
            // The admin-configured term, priced against the DELIVERED total:
            // ₦800 of goods plus ₦1,500 delivery over four payments. A plan
            // carries the delivery fee exactly as a card checkout does, so
            // quoting the goods alone would advertise an instalment the plan
            // would never actually ask for.
            ->has('planTerms', 1)
            ->where('planTerms.0.installments', 4)
            ->where('planTerms.0.installmentKobo', (int) ceil(planTarget(800_00) / 4))
            // Under the free-delivery threshold, so shipping is charged.
            ->where('summary.subtotalKobo', 800_00)
            ->where('summary.shippingKobo', 1500_00)
            ->where('summary.totalKobo', 2300_00));
});

it('rejects an unavailable payment method', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);
    $this->actingAs($user)->post(route('cart.items.store'), ['product_uuid' => $product->uuid]);

    $this->actingAs($user)->post(route('cart.checkout.store'), [
        ...deliveryFields(),
        'payment_method' => 'opay',
    ])->assertSessionHasErrors('payment_method');
});

it('refuses to start a plan without a term', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);
    $this->actingAs($user)->post(route('cart.items.store'), ['product_uuid' => $product->uuid]);

    $this->actingAs($user)->post(route('cart.checkout.store'), [
        ...deliveryFields(),
        'payment_method' => 'pay_small_small',
    ])->assertSessionHasErrors('plan_term_id');

    expect(SavingsGoal::query()->count())->toBe(0);
});

it('starts a plan that divides the locked price into instalments, charging nothing', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 50_000_00]);

    $plan = planFromCheckout($user, $product, $this->term, quantity: 2);

    expect($plan->status)->toBe(SavingsGoalStatus::Saving)
        // Goods plus delivery: a plan is not a way to dodge the delivery fee.
        ->and($plan->target_kobo)->toBe(planTarget(100_000_00))
        ->and($plan->installments)->toBe(4)
        ->and($plan->installment_kobo)->toBe((int) ceil(planTarget(100_000_00) / 4))
        ->and($plan->cadence)->toBe(PlanCadence::Monthly)
        ->and($plan->paid_kobo)->toBe(0)
        ->and($plan->next_due_at)->not->toBeNull()
        // Nothing charged, and the cart has been handed to the plan.
        ->and($user->refresh()->cart?->items()->count() ?? 0)->toBe(0);
});

it('tracks instalments and only allows collection once the plan is fully paid', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 40_000_00]);
    $plan = planFromCheckout($user, $product, $this->term);

    $plans = app(SavingsGoalService::class);

    // A quarter of the whole target, delivery included.
    $instalment = (int) ceil(planTarget(40_000_00) / 4);

    // Three of four instalments in — still short.
    foreach (range(1, 3) as $i) {
        $plans->recordPayment($user, $plan->refresh(), $instalment, reference: "TEST-PAY-{$i}");
    }

    expect($plan->refresh()->paid_kobo)->toBe($instalment * 3)
        ->and($plan->isCovered())->toBeFalse()
        ->and($plan->installmentsPaid())->toBe(3);

    $this->actingAs($user)->post(route('savings.goals.buy', $plan->uuid))
        ->assertSessionHasErrors('goal');

    // The last one completes it.
    $plans->recordPayment($user, $plan->refresh(), $instalment, reference: 'TEST-PAY-4');

    expect($plan->refresh()->isCovered())->toBeTrue();

    $this->actingAs($user)->post(route('savings.goals.buy', $plan->uuid))
        ->assertRedirect(route('orders.index'));

    expect($plan->refresh()->status)->toBe(SavingsGoalStatus::Fulfilled);
});

it('never banks more than the plan still owes', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 40_000_00]);
    $plan = planFromCheckout($user, $product, $this->term);

    app(SavingsGoalService::class)->recordPayment($user, $plan, 99_000_00, reference: 'TEST-OVERPAY');

    expect($plan->refresh()->paid_kobo)->toBe(planTarget(40_000_00));
});

it('does not pay a plan twice when the same webhook reference arrives again', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 40_000_00]);
    $plan = planFromCheckout($user, $product, $this->term);

    $plans = app(SavingsGoalService::class);
    $plans->recordPayment($user, $plan, 10_000_00, reference: 'TEST-DUP');
    $plans->recordPayment($user, $plan->refresh(), 10_000_00, reference: 'TEST-DUP');

    expect($plan->refresh()->paid_kobo)->toBe(10_000_00)
        ->and($plan->payments()->count())->toBe(1);
});

it('keeps the plan price frozen when the vendor later raises it', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 40_000_00]);
    $plan = planFromCheckout($user, $product, $this->term);

    $product->forceFill(['price_kobo' => 90_000_00])->save();

    app(SavingsGoalService::class)
        ->recordPayment($user, $plan, planTarget(40_000_00), reference: 'TEST-FULL');

    $this->actingAs($user)->post(route('savings.goals.buy', $plan->uuid))->assertRedirect();

    expect($plan->refresh()->orders()->value('locked_price_kobo'))->toBe(40_000_00);
});

it('carries what was paid into credit when a plan is cancelled, losing nothing', function () {
    $user = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 40_000_00]);
    $plan = planFromCheckout($user, $product, $this->term);

    app(SavingsGoalService::class)->recordPayment($user, $plan, 15_000_00, reference: 'TEST-PART');

    $this->actingAs($user)->post(route('savings.goals.cancel', $plan->uuid))->assertRedirect();

    expect($plan->refresh()->status)->toBe(SavingsGoalStatus::Cancelled)
        ->and(app(SavingsService::class)->creditKobo($user))->toBe(15_000_00);
});

it('applies leftover credit to the next plan automatically', function () {
    $user = checkoutCustomer();
    $first = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 40_000_00]);
    $plan = planFromCheckout($user, $first, $this->term);

    app(SavingsGoalService::class)->recordPayment($user, $plan, 15_000_00, reference: 'TEST-PART');
    $this->actingAs($user)->post(route('savings.goals.cancel', $plan->uuid));

    $second = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 60_000_00]);
    $newPlan = planFromCheckout($user, $second, $this->term);

    expect($newPlan->paid_kobo)->toBe(15_000_00)
        ->and(app(SavingsService::class)->creditKobo($user))->toBe(0);
});

it('keeps one customer\'s plan invisible to another', function () {
    $owner = checkoutCustomer();
    $intruder = checkoutCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);

    $plan = planFromCheckout($owner, $product, $this->term);

    $this->actingAs($intruder)->get(route('savings.goals.show', $plan->uuid))->assertForbidden();
    $this->actingAs($intruder)->post(route('savings.goals.buy', $plan->uuid))->assertForbidden();
    $this->actingAs($intruder)->post(route('savings.goals.pay', $plan->uuid))->assertForbidden();
});
