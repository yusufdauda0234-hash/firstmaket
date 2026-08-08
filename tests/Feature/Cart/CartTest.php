<?php

use App\Models\User;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 8 QA: cart CRUD. Checkout (pay-in-full / bundle into a plan) is
 * covered separately in CartCheckoutTest and Savings/BundlePlanTest
 * (docs/FirstMaket_Implementation_Plan.md Sprint 8).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create();
    $this->customer->assignRole('Customer');
});

it('adds a product to the cart, creating the cart lazily', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 2])
        ->assertRedirect();

    $cart = Cart::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($cart->items)->toHaveCount(1)
        ->and($cart->items->first()->quantity)->toBe(2)
        ->and($cart->items->first()->product_id)->toBe($product->id);
});

it('increases quantity instead of duplicating a row when adding the same product twice', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 10]);

    $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 2]);
    $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 3]);

    $cart = Cart::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($cart->items)->toHaveCount(1)
        ->and($cart->items->first()->quantity)->toBe(5);
});

it('holds items from more than one vendor in the same cart', function () {
    $productA = Product::factory()->approved()->create(['stock_quantity' => 5]);
    $productB = Product::factory()->approved()->create(['stock_quantity' => 5]);

    $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_uuid' => $productA->uuid]);
    $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_uuid' => $productB->uuid]);

    $cart = Cart::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($cart->items)->toHaveCount(2);
});

it('refuses to add more than available stock', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 2]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 3])
        ->assertSessionHasErrors('quantity');

    expect(CartItem::query()->count())->toBe(0);
});

it('refuses to add a product that is not approved', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid])
        ->assertSessionHasErrors('product');

    expect(CartItem::query()->count())->toBe(0);
});

it('updates an item quantity', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 10]);
    $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_uuid' => $product->uuid]);

    $this->actingAs($this->customer)
        ->patch(route('cart.items.update', $product->uuid), ['quantity' => 4])
        ->assertRedirect();

    expect(CartItem::query()->firstOrFail()->quantity)->toBe(4);
});

it('removes an item from the cart', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 10]);
    $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_uuid' => $product->uuid]);

    $this->actingAs($this->customer)
        ->delete(route('cart.items.destroy', $product->uuid))
        ->assertRedirect();

    expect(CartItem::query()->count())->toBe(0);
});

/*
 * Lines are addressed by product uuid rather than cart-item id, so reaching
 * another shopper's cart is not expressible: the same uuid resolves to each
 * caller's own line. This replaces the old ownership check, which existed
 * only because the route took a cart-item id.
 */
it('scopes a mutation to the caller\'s own cart when two customers hold the same product', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 10]);

    $intruder = User::factory()->create();
    $intruder->assignRole('Customer');

    $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_uuid' => $product->uuid]);
    $this->actingAs($intruder)->post(route('cart.items.store'), ['product_uuid' => $product->uuid]);

    $this->actingAs($intruder)->patch(route('cart.items.update', $product->uuid), ['quantity' => 9]);

    $ownCart = Cart::query()->where('user_id', $this->customer->id)->firstOrFail();
    $intruderCart = Cart::query()->where('user_id', $intruder->id)->firstOrFail();

    expect($ownCart->items()->value('quantity'))->toBe(1)
        ->and($intruderCart->items()->value('quantity'))->toBe(9);
});

it('caps a repeated add at available stock instead of letting it creep past one unit at a time', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 2]);

    $this->actingAs($this->customer)->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 2]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1])
        ->assertSessionHasErrors('quantity');

    expect(CartItem::query()->firstOrFail()->quantity)->toBe(2);
});
