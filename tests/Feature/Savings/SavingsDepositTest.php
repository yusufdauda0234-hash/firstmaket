<?php

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Savings\Models\PlanPayment;
use App\Shared\Enums\PaystackTransactionStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

/**
 * Money only ever enters FirstMaket through a signature-verified, idempotent
 * Paystack webhook — never from the browser — and it always arrives attached
 * to something specific: a card checkout, or one instalment on a Pay Small
 * Small plan. There is no balance to top up and no withdrawal path.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.paystack.secret_key' => 'sk_test_webhook_secret']);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

/**
 * @param  array<string, mixed>  $attributes  purpose + its target
 */
function pendingPaystackTransaction(User $user, string $reference, int $amountKobo, array $attributes = []): PaystackTransaction
{
    return PaystackTransaction::query()->create([
        'user_id' => $user->id,
        'paystack_reference' => $reference,
        'amount_kobo' => $amountKobo,
        'currency' => 'NGN',
        'status' => PaystackTransactionStatus::Pending,
        ...$attributes,
    ]);
}

/** A pending card checkout for one product, ready for its webhook. */
function pendingCardCheckout(User $customer, Product $product): CheckoutSession
{
    test()->actingAs($customer)->post(route('cart.items.store'), ['product_uuid' => $product->uuid]);

    return app(CartCheckoutService::class)->startCardCheckout(
        $customer,
        app(CartService::class)->lines($customer),
        [
            'recipient_name' => 'Yakubu Dauda',
            'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ],
    );
}

it('raises the orders when a card checkout charge clears', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    $session = pendingCardCheckout($this->customer, $product);

    pendingPaystackTransaction($this->customer, $session->paystack_reference, $session->total_amount_kobo, [
        'purpose' => 'order',
        'checkout_session_id' => $session->id,
    ]);

    postWebhook(chargeSuccessPayload($session->paystack_reference, $session->total_amount_kobo))->assertOk();

    expect($session->refresh()->status)->toBe('paid')
        ->and($session->paid_at)->not->toBeNull()
        ->and(Order::query()->where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('credits a plan instalment when its charge clears', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00, 'stock_quantity' => 5]);
    $plan = testPlan($this->customer, $product);

    pendingPaystackTransaction($this->customer, 'FMP_ref_1', 20_000_00, [
        'purpose' => 'plan_installment',
        'savings_goal_id' => $plan->id,
    ]);

    postWebhook(chargeSuccessPayload('FMP_ref_1', 20_000_00))->assertOk();

    expect($plan->refresh()->paid_kobo)->toBe(20_000_00)
        ->and($plan->payments()->count())->toBe(1);
});

it('does not double-credit when the same webhook is delivered twice', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00, 'stock_quantity' => 5]);
    $plan = testPlan($this->customer, $product);

    pendingPaystackTransaction($this->customer, 'FMP_dup', 15_000_00, [
        'purpose' => 'plan_installment',
        'savings_goal_id' => $plan->id,
    ]);

    postWebhook(chargeSuccessPayload('FMP_dup', 15_000_00))->assertOk();
    postWebhook(chargeSuccessPayload('FMP_dup', 15_000_00))->assertOk();

    expect($plan->refresh()->paid_kobo)->toBe(15_000_00)
        ->and(PlanPayment::query()->count())->toBe(1);
});

it('rejects a webhook with an invalid signature and moves no money', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00, 'stock_quantity' => 5]);
    $plan = testPlan($this->customer, $product);

    pendingPaystackTransaction($this->customer, 'FMP_bad', 10_000_00, [
        'purpose' => 'plan_installment',
        'savings_goal_id' => $plan->id,
    ]);

    postWebhook(chargeSuccessPayload('FMP_bad', 10_000_00), signature: 'not-a-valid-signature')
        ->assertStatus(400);

    expect($plan->refresh()->paid_kobo)->toBe(0)
        ->and(PlanPayment::query()->count())->toBe(0);
});

it('ignores a charge.success for an unknown reference', function () {
    postWebhook(chargeSuccessPayload('FMP_never_seen', 5_000_00))->assertOk();

    expect(PlanPayment::query()->count())->toBe(0)
        ->and(Order::query()->count())->toBe(0);
});

it('never moves money from the browser callback', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 40_000_00, 'stock_quantity' => 5]);
    $plan = testPlan($this->customer, $product);

    pendingPaystackTransaction($this->customer, 'FMP_callback', 10_000_00, [
        'purpose' => 'plan_installment',
        'savings_goal_id' => $plan->id,
    ]);

    // The customer returning from Paystack proves nothing — only the signed
    // webhook does.
    $this->actingAs($this->customer)
        ->get(route('payment.callback', ['reference' => 'FMP_callback']))
        ->assertOk();

    expect($plan->refresh()->paid_kobo)->toBe(0);
});

it('keeps paid_before/after consistent across sequential instalments', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 60_000_00, 'stock_quantity' => 5]);
    $plan = testPlan($this->customer, $product);

    foreach ([['FMP_a', 10_000_00], ['FMP_b', 25_000_00], ['FMP_c', 5_000_00]] as [$reference, $amount]) {
        pendingPaystackTransaction($this->customer, $reference, $amount, [
            'purpose' => 'plan_installment',
            'savings_goal_id' => $plan->id,
        ]);
        postWebhook(chargeSuccessPayload($reference, $amount))->assertOk();
    }

    $payments = PlanPayment::query()->orderBy('id')->get();

    expect($plan->refresh()->paid_kobo)->toBe(40_000_00)
        ->and($payments->pluck('paid_before_kobo')->all())->toBe([0, 10_000_00, 35_000_00])
        ->and($payments->pluck('paid_after_kobo')->all())->toBe([10_000_00, 35_000_00, 40_000_00]);
});

it('exposes no withdrawal or top-up route anywhere', function () {
    $forbidden = ['withdraw', 'cash-out', 'transfer-out', 'add-money', 'deposit'];

    $matches = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        // Vendor payouts are a separate, staff-run ledger — not a customer
        // cash-out, which is what this guards against.
        ->reject(fn (string $uri) => str_contains($uri, 'payouts'))
        ->filter(fn (string $uri) => collect($forbidden)->contains(fn ($word) => str_contains($uri, $word)));

    expect($matches)->toBeEmpty();
});
