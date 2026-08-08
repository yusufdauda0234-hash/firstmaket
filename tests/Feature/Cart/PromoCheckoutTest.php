<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Models\PromoRedemption;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * A promo code carried through a real card checkout.
 *
 * The unit tests prove the arithmetic; this proves the plumbing — that the
 * discount reaches the amount Paystack is asked for, lands on the orders,
 * comes out of commission rather than the vendor's earning, and is only
 * spent once the money actually arrives.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    // Discounts are funded out of commission and capped by it, so a platform
    // default of 0 would cap every promo in this file to nothing. These tests
    // are about the discount, not about what the cut happens to be.
    Setting::set('orders.default_commission_percent', 10);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

function tenPercentOff(array $attributes = []): PromoCode
{
    return PromoCode::query()->create(array_merge([
        'code' => 'SAVE10',
        'type' => 'percent',
        'percent_off' => '10.00',
        'max_discount_kobo' => 100_000_00,
        'is_active' => true,
    ], $attributes));
}

function cartLine(User $customer, Product $product, int $quantity = 1): void
{
    test()->actingAs($customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => $quantity])
        ->assertRedirect();
}

function checkoutWithPromo(User $customer, ?string $code): CheckoutSession
{
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
        $code,
    );
}

// ── Applying it ─────────────────────────────────────────────────────────

it('applies a code to the cart', function () {
    tenPercentOff();
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]));

    $this->actingAs($this->customer)
        ->post(route('cart.promo.apply'), ['promo_code' => 'save10'])
        ->assertRedirect()
        ->assertSessionHas('cart.promo_code', 'SAVE10');
});

it('rejects a code the customer cannot use, without storing it', function () {
    tenPercentOff(['min_order_kobo' => 100_000_00]);
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 5_000_00, 'stock_quantity' => 5,
    ]));

    $this->actingAs($this->customer)
        ->post(route('cart.promo.apply'), ['promo_code' => 'SAVE10'])
        ->assertSessionHasErrors('promo_code')
        ->assertSessionMissing('cart.promo_code');
});

it('drops a code that stopped applying before checkout is opened', function () {
    $code = tenPercentOff();
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]));

    $this->actingAs($this->customer)->post(route('cart.promo.apply'), ['promo_code' => 'SAVE10']);

    // Switched off between applying and paying.
    $code->forceFill(['is_active' => false])->save();

    $this->actingAs($this->customer)
        ->get(route('cart.checkout'))
        ->assertInertia(fn ($page) => $page->where('promo', null));
});

it('lets a shopper take the code back off', function () {
    tenPercentOff();
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]));

    $this->actingAs($this->customer)->post(route('cart.promo.apply'), ['promo_code' => 'SAVE10']);

    $this->actingAs($this->customer)
        ->delete(route('cart.promo.remove'))
        ->assertRedirect()
        ->assertSessionMissing('cart.promo_code');
});

// ── Reaching the charge ─────────────────────────────────────────────────

it('takes the discount off what the customer is actually charged', function () {
    tenPercentOff();
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]), 2);

    $session = checkoutWithPromo($this->customer, 'SAVE10');

    // ₦100,000 goods + ₦1,500 delivery, less 10% of the goods.
    expect($session->promo_discount_kobo)->toBe(10_000_00)
        ->and($session->total_amount_kobo)->toBe(91_500_00);
});

it('charges the full price when no code was applied', function () {
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]));

    $session = checkoutWithPromo($this->customer, null);

    expect($session->promo_discount_kobo)->toBe(0)
        ->and($session->promo_code_id)->toBeNull()
        ->and($session->total_amount_kobo)->toBe(51_500_00);
});

it('does not spend the code until the payment clears', function () {
    tenPercentOff();
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]));

    checkoutWithPromo($this->customer, 'SAVE10');

    // Abandoned at Paystack: nobody paid, so nobody used the code.
    expect(PromoRedemption::query()->count())->toBe(0);
});

// ── Landing on the orders ───────────────────────────────────────────────

it('spreads the discount across the units and spends the code', function () {
    tenPercentOff();
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]), 3);

    $session = checkoutWithPromo($this->customer, 'SAVE10');
    app(CartCheckoutService::class)->completePaidSession($session);

    $orders = Order::query()->get();

    // ₦15,000 over three units — the shares must sum to exactly that, which
    // naive per-unit rounding of ₦5,000 each would only manage by luck.
    expect($orders)->toHaveCount(3)
        ->and($orders->sum('promo_discount_kobo'))->toBe(15_000_00)
        ->and(PromoRedemption::query()->count())->toBe(1);
});

it('splits the discount by price when the basket is uneven', function () {
    tenPercentOff();
    $dear = Product::factory()->approved()->create(['price_kobo' => 80_000_00, 'stock_quantity' => 5]);
    $cheap = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    cartLine($this->customer, $dear);
    cartLine($this->customer, $cheap);

    $session = checkoutWithPromo($this->customer, 'SAVE10');
    app(CartCheckoutService::class)->completePaidSession($session);

    // 10% of ₦100,000 is ₦10,000: ₦8,000 on the dear item, ₦2,000 on the cheap.
    expect(Order::query()->where('product_id', $dear->id)->value('promo_discount_kobo'))->toBe(8_000_00)
        ->and(Order::query()->where('product_id', $cheap->id)->value('promo_discount_kobo'))->toBe(2_000_00);
});

it('leaves the vendor earning untouched — the discount comes out of commission', function () {
    tenPercentOff();
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]));

    $session = checkoutWithPromo($this->customer, 'SAVE10');
    app(CartCheckoutService::class)->completePaidSession($session);

    $order = Order::query()->firstOrFail();

    // The vendor is paid as though the customer had paid full price. That is
    // the whole basis on which FirstMaket may run a promotion without asking.
    expect($order->promo_discount_kobo)->toBe(5_000_00)
        ->and($order->vendor_earning_amount_kobo)
        ->toBe($order->locked_price_kobo - $order->commission_amount_kobo);
});

it('never discounts a unit by more than the commission on it', function () {
    // A ₦4,000 fixed discount on one ₦10,000 item whose commission is ₦1,000.
    tenPercentOff(['code' => 'FLAT4K', 'type' => 'fixed', 'percent_off' => null, 'amount_off_kobo' => 4_000_00]);
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 10_000_00, 'stock_quantity' => 5,
    ]));

    $session = checkoutWithPromo($this->customer, 'FLAT4K');
    app(CartCheckoutService::class)->completePaidSession($session);

    $order = Order::query()->firstOrFail();

    expect($order->promo_discount_kobo)->toBeLessThanOrEqual($order->commission_amount_kobo);
});

it('does not spend the code twice when the webhook is replayed', function () {
    tenPercentOff();
    cartLine($this->customer, Product::factory()->approved()->create([
        'price_kobo' => 50_000_00, 'stock_quantity' => 5,
    ]));

    $session = checkoutWithPromo($this->customer, 'SAVE10');
    app(CartCheckoutService::class)->completePaidSession($session);
    app(CartCheckoutService::class)->completePaidSession($session->fresh());

    expect(PromoRedemption::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1);
});
