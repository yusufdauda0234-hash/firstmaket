<?php

use App\Models\User;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\ProductStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 8 QA: cart pay-in-full checkout. Resolves the previously-blocked
 * delivery-address-timing design question — address is collected upfront on
 * the checkout screen, before the single wallet debit for the cart total —
 * and creates one order per unit, possibly across several vendors, all in
 * one transaction.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

function fundCartWallet(User $user, int $amountKobo): void
{
    app(WalletService::class)->creditDeposit($user, $amountKobo, 'TEST-DEP-'.fake()->unique()->uuid());
}

function addToCart(User $customer, Product $product, int $quantity = 1): void
{
    test()->actingAs($customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => $quantity])
        ->assertRedirect();
}

it('pays for a multi-vendor cart in one wallet debit and creates one order per vendor', function () {
    $productA = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    $productB = Product::factory()->approved()->create(['price_kobo' => 30_000_00, 'stock_quantity' => 5]);

    addToCart($this->customer, $productA);
    addToCart($this->customer, $productB);

    fundCartWallet($this->customer, 100_000_00);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), [
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ])
        ->assertRedirect(route('orders.index'));

    // One wallet debit for the combined total.
    expect(app(WalletService::class)->getOrCreate($this->customer)->balance_kobo)->toBe(20_000_00);

    $orders = Order::query()->where('customer_id', $this->customer->id)->get();

    expect($orders)->toHaveCount(2)
        ->and($orders->pluck('vendor_id')->unique())->toHaveCount(2)
        ->and($orders->pluck('checkout_session_id')->unique())->toHaveCount(1)
        ->and($orders->every(fn (Order $order) => $order->plan_id === null))->toBeTrue()
        ->and($orders->every(fn (Order $order) => $order->delivery_address === '12 Marina Road'))->toBeTrue()
        ->and($orders->every(fn (Order $order) => $order->status->value === 'pending'))->toBeTrue();

    // Every purchased item left the cart.
    expect(CartItem::query()->where('cart_id', Cart::query()->where('user_id', $this->customer->id)->value('id'))->count())->toBe(0);
});

it('fans a quantity greater than one out into that many separate orders at the correct unit price', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    addToCart($this->customer, $product, 3);

    fundCartWallet($this->customer, 60_000_00);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), [
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ])
        ->assertRedirect();

    $orders = Order::query()->where('customer_id', $this->customer->id)->get();

    expect($orders)->toHaveCount(3)
        ->and($orders->every(fn (Order $order) => $order->locked_price_kobo === 20_000_00))->toBeTrue()
        ->and(app(WalletService::class)->getOrCreate($this->customer)->balance_kobo)->toBe(0);
});

it('re-validates stock and approval at checkout, not just at add-to-cart, and leaves the failed item in the cart uncharged', function () {
    $available = Product::factory()->approved()->create(['price_kobo' => 40_000_00, 'stock_quantity' => 5]);
    $goesOutOfStock = Product::factory()->approved()->create(['price_kobo' => 25_000_00, 'stock_quantity' => 5]);

    addToCart($this->customer, $available);
    addToCart($this->customer, $goesOutOfStock);

    // Sells out after being added to the cart.
    $goesOutOfStock->forceFill(['stock_quantity' => 0])->save();

    fundCartWallet($this->customer, 100_000_00);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), [
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ])
        ->assertRedirect(route('orders.index'));

    // Only the available item was purchased and only its price was debited.
    expect(app(WalletService::class)->getOrCreate($this->customer)->balance_kobo)->toBe(60_000_00);

    $orders = Order::query()->where('customer_id', $this->customer->id)->get();
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->product_id)->toBe($available->id);

    // The unavailable item is still sitting in the cart.
    $remaining = CartItem::query()->where('product_id', $goesOutOfStock->id)->first();
    expect($remaining)->not->toBeNull();
});

it('blocks checkout entirely and charges nothing when every cart item is unavailable', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);
    addToCart($this->customer, $product);

    $product->forceFill(['status' => ProductStatus::Delisted])->save();

    fundCartWallet($this->customer, 100_000_00);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), [
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ])
        ->assertSessionHasErrors('cart');

    expect(Order::query()->count())->toBe(0)
        ->and(app(WalletService::class)->getOrCreate($this->customer)->balance_kobo)->toBe(100_000_00);
});

it('refuses checkout when the wallet cannot cover the cart total, charging nothing', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    addToCart($this->customer, $product);

    fundCartWallet($this->customer, 10_000_00);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), [
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ])
        ->assertSessionHasErrors('amount');

    expect(Order::query()->count())->toBe(0)
        ->and(app(WalletService::class)->getOrCreate($this->customer)->balance_kobo)->toBe(10_000_00);
});

it('never exposes the delivery address on the vendor orders screen for a cart-checkout order', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    $vendorUser = $product->vendor->user;
    $vendorUser->assignRole('Vendor');

    addToCart($this->customer, $product);
    fundCartWallet($this->customer, 20_000_00);

    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), [
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ])
        ->assertRedirect();

    $response = $this->actingAs($vendorUser)
        ->get('http://'.config('app.vendor_domain').'/orders')
        ->assertOk();

    $serialized = json_encode($response->viewData('page')['props']['orders']);

    expect($serialized)->not->toContain($this->customer->name)
        ->and($serialized)->not->toContain('12 Marina Road');
});
