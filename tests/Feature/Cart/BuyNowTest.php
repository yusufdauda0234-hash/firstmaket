<?php

use App\Models\User;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Shared\Enums\ProductStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * "Buy now" takes one item straight to checkout without putting it in the
 * cart. The cart a shopper has already built must survive that entirely —
 * before, during, and after the payment clears.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

it('checks out a single product without adding it to the cart', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 9]);

    $this->actingAs($this->customer)
        ->get(route('cart.checkout', ['buy_now' => $product->uuid, 'qty' => 3]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Cart/Checkout')
            ->has('items', 1)
            ->where('items.0.quantity', 3)
            ->where('buyNow.productUuid', $product->uuid)
            ->where('summary.subtotalKobo', 60_000_00));

    expect(app(CartService::class)->count($this->customer))->toBe(0);
});

it('leaves an existing cart untouched while buying something else outright', function () {
    $inCart = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 9]);
    $buyNow = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 9]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $inCart->uuid, 'quantity' => 2]);

    $this->actingAs($this->customer)
        ->get(route('cart.checkout', ['buy_now' => $buyNow->uuid, 'qty' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.productUuid', $buyNow->uuid));

    // The cart still holds only what was actually saved to it.
    expect(app(CartService::class)->count($this->customer))->toBe(2);
});

it('caps the quantity at available stock', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 1_000_00, 'stock_quantity' => 4]);

    $this->actingAs($this->customer)
        ->get(route('cart.checkout', ['buy_now' => $product->uuid, 'qty' => 99]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('items.0.quantity', 4));
});

it('falls back to the cart when the buy-now product is not purchasable', function () {
    $inCart = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 9]);
    $draft = Product::factory()->create(['status' => ProductStatus::Draft, 'stock_quantity' => 9]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $inCart->uuid, 'quantity' => 1]);

    // An unapproved product must not become buyable through the query string.
    $this->actingAs($this->customer)
        ->get(route('cart.checkout', ['buy_now' => $draft->uuid, 'qty' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.productUuid', $inCart->uuid)
            ->where('buyNow', null));
});

it('does not delete the shopper\'s cart row when the same product is bought outright', function () {
    // The trap: buy-now the very product already sitting in the cart. Clearing
    // by product id would wipe the saved line the shopper never checked out.
    $product = Product::factory()->approved()->create(['price_kobo' => 10_000_00, 'stock_quantity' => 20]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 2]);

    $session = app(CartCheckoutService::class)->startCardCheckout(
        $this->customer,
        app(CartService::class)->buyNowLines($product, 1),
        [
            'recipient_name' => 'Yakubu Dauda',
            'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ],
    );

    app(CartCheckoutService::class)->completePaidSession($session);

    expect(app(CartService::class)->count($this->customer))->toBe(2);
});

it('still clears the cart rows a normal cart checkout paid for', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 10_000_00, 'stock_quantity' => 20]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 2]);

    $session = app(CartCheckoutService::class)->startCardCheckout(
        $this->customer,
        app(CartService::class)->lines($this->customer),
        [
            'recipient_name' => 'Yakubu Dauda',
            'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ],
    );

    app(CartCheckoutService::class)->completePaidSession($session);

    expect(CartItem::query()->count())->toBe(0);
});
