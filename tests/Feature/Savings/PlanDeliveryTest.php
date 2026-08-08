<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\DeliveryRate;
use App\Modules\Orders\Services\DeliveryPricing;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\PlanCadence;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Delivery on a Pay Small Small plan.
 *
 * A plan used to charge nothing to deliver while a card checkout charged the
 * full rate, so the same basket cost less paid off over six months than paid
 * outright — backwards, and against the rule that nothing is free unless an
 * admin has set it so on the delivery-rates page.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

function deliveryTerm(int $months = 4): PlanTerm
{
    return PlanTerm::query()->firstOrCreate(
        ['cadence' => PlanCadence::Monthly, 'duration_months' => $months],
        ['name' => "Delivery test · x{$months}", 'min_target_kobo' => 0, 'is_active' => true],
    );
}

function planFor(User $customer, Product $product, int $quantity = 1, string $state = 'Lagos')
{
    return app(SavingsGoalService::class)->createFromLines(
        $customer,
        collect([['cartItemId' => null, 'product' => $product, 'quantity' => $quantity]]),
        [
            'recipient_name' => 'Yakubu Dauda',
            'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road',
            'state' => $state,
            'lga' => 'Eti-Osa',
        ],
        deliveryTerm(),
    );
}

it('builds the target from the goods and the delivery fee', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00]);

    $goal = planFor($this->customer, $product);

    expect($goal->delivery_fee_kobo)->toBe(1_500_00)
        ->and($goal->target_kobo)->toBe(41_500_00);
});

it('charges a plan the same delivery as a card checkout', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00]);

    $goal = planFor($this->customer, $product);

    // The figure the delivery-rates page says, not a number of its own.
    expect($goal->delivery_fee_kobo)
        ->toBe(app(DeliveryPricing::class)->feeKobo(40_000_00, 'Lagos'));
});

it('quotes the rate for the state the plan is going to', function () {
    DeliveryRate::query()->create([
        'state' => 'Gombe',
        'fee_kobo' => 2_000_00 + 1_000_00,
        'free_threshold_kobo' => 0,
        'is_active' => true,
    ]);

    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00]);

    expect(planFor($this->customer, $product, state: 'Gombe')->delivery_fee_kobo)->toBe(3_000_00);
});

it('honours a free-delivery threshold set by an admin', function () {
    DeliveryRate::query()->create([
        'state' => 'Kano',
        'fee_kobo' => 1_000_00 + 500_00,
        // Free above ₦30,000, exactly as it would be on a card checkout.
        'free_threshold_kobo' => 30_000_00,
        'is_active' => true,
    ]);

    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00]);
    $goal = planFor($this->customer, $product, state: 'Kano');

    expect($goal->delivery_fee_kobo)->toBe(0)
        ->and($goal->target_kobo)->toBe(40_000_00);
});

it('spreads the delivery fee across the instalments', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00]);

    $goal = planFor($this->customer, $product);

    // Nothing is owed at the door — the last instalment settles it all.
    expect($goal->installment_kobo)->toBe((int) ceil(41_500_00 / 4));
});

it('puts the collected fee on the order when the plan completes', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00, 'stock_quantity' => 5]);
    $goal = planFor($this->customer, $product);

    app(SavingsGoalService::class)
        ->recordPayment($this->customer, $goal, $goal->target_kobo, reference: 'PAY-ALL');

    $orders = app(SavingsGoalService::class)->fulfil($this->customer, $goal->refresh());

    expect($orders->first()->checkoutSession->shipping_fee_kobo)->toBe(1_500_00)
        ->and($orders->first()->checkoutSession->total_amount_kobo)->toBe(41_500_00);
});

it('re-quotes the fee when the plan is switched to a different basket', function () {
    DeliveryRate::query()->whereNull('state')->update(['free_threshold_kobo' => 50_000_00]);

    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00]);
    $goal = planFor($this->customer, $product);

    // Under the threshold, so delivery is charged.
    expect($goal->delivery_fee_kobo)->toBe(1_500_00);

    $dearer = Product::factory()->approved()->create(['price_kobo' => 60_000_00]);
    $goal = app(SavingsGoalService::class)->switchTo(
        $this->customer,
        $goal->refresh(),
        collect([['product' => $dearer, 'quantity' => 1]]),
    );

    // Over it now. Carrying the old fee would charge for a band the new
    // basket is no longer in.
    expect($goal->delivery_fee_kobo)->toBe(0)
        ->and($goal->target_kobo)->toBe(60_000_00);
});
