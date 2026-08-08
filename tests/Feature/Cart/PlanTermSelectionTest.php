<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\PlanCadence;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakePaymentGateway;

/**
 * The plan term posted from checkout.
 *
 * Card checkouts send no term at all, and the rule has to let that through —
 * running `exists` on a null answered "the selected plan term id is invalid"
 * on a payment method that never had a plan to begin with.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 152_000_00]);

    $this->term = PlanTerm::query()->create([
        'name' => 'Weekly over 1 month',
        'cadence' => PlanCadence::Weekly,
        'duration_months' => 1,
        'min_target_kobo' => 0,
        'is_active' => true,
    ]);
});

/** @return array<string, mixed> */
function checkoutPayload(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Musa Ibrahim',
        'recipient_phone' => '08031234567',
        'delivery_address' => '12 Marina Road',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'landmark' => '',
        'payment_method' => 'card',
    ], $overrides);
}

function fillCart(User $customer, Product $product): void
{
    test()->actingAs($customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1]);
}

it('does not reject a card checkout that sends no plan term', function () {
    fillCart($this->customer, $this->product);

    // A null term is exactly what the form posts when paying by card.
    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), checkoutPayload(['plan_term_id' => null]))
        ->assertSessionDoesntHaveErrors('plan_term_id');
});

it('does not reject a card checkout that sends an empty plan term', function () {
    fillCart($this->customer, $this->product);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), checkoutPayload(['plan_term_id' => '']))
        ->assertSessionDoesntHaveErrors('plan_term_id');
});

it('starts a plan when a valid term is chosen', function () {
    // This term charges at checkout, so the request ends at the gateway.
    $this->app->instance(PaymentGatewayContract::class, new FakePaymentGateway);

    fillCart($this->customer, $this->product);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), checkoutPayload([
            'payment_method' => 'pay_small_small',
            'plan_term_id' => $this->term->id,
        ]))
        ->assertSessionDoesntHaveErrors();

    expect(SavingsGoal::query()->where('user_id', $this->customer->id)->count())->toBe(1);
});

it('asks for a schedule when Pay Small Small arrives without one', function () {
    fillCart($this->customer, $this->product);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), checkoutPayload([
            'payment_method' => 'pay_small_small',
            'plan_term_id' => null,
        ]))
        ->assertSessionHasErrors(['plan_term_id' => 'Choose how you want to pay it off.']);
});

it('rejects a term that is no longer offered', function () {
    fillCart($this->customer, $this->product);

    $this->term->update(['is_active' => false]);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), checkoutPayload([
            'payment_method' => 'pay_small_small',
            'plan_term_id' => $this->term->id,
        ]))
        ->assertSessionHasErrors([
            'plan_term_id' => 'That plan is no longer available. Pick one of the plans listed.',
        ]);
});

it('rejects a term id that does not exist', function () {
    fillCart($this->customer, $this->product);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), checkoutPayload([
            'payment_method' => 'pay_small_small',
            'plan_term_id' => 99999,
        ]))
        ->assertSessionHasErrors('plan_term_id');
});

it('only offers terms this order total clears', function () {
    fillCart($this->customer, $this->product);

    // Priced above this ₦152,000 order, so it must not be listed.
    PlanTerm::query()->create([
        'name' => 'Monthly over 12 months',
        'cadence' => PlanCadence::Monthly,
        'duration_months' => 12,
        'min_target_kobo' => 200_000_00,
        'is_active' => true,
    ]);

    $this->actingAs($this->customer)
        ->get(route('cart.checkout'))
        ->assertInertia(fn ($page) => $page
            ->has('planTerms', 1)
            ->where('planTerms.0.id', $this->term->id));
});

it('reports the weekly instalment for the order total', function () {
    fillCart($this->customer, $this->product);

    // ₦152,000 of goods plus delivery, over four weekly payments. A plan
    // carries the delivery fee like a card checkout does, so the quote is
    // the delivered total — anything less would advertise an instalment the
    // plan would never actually ask for.
    $this->actingAs($this->customer)
        ->get(route('cart.checkout'))
        ->assertInertia(fn ($page) => $page
            ->where('planTerms.0.installments', 4)
            ->where('planTerms.0.installmentKobo', (int) ceil(planTarget(152_000_00) / 4))
            ->where('planTerms.0.cadenceLabel', 'Weekly'));
});
