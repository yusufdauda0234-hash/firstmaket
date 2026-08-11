<?php

use App\Models\User;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Campaign;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cart checkout paid by card.
 *
 * With no balance to debit, checkout is two steps: the cart is frozen into a
 * pending session and the shopper goes to Paystack, then the verified
 * webhook completes the session and raises the orders. Nothing exists in
 * between that a failed payment could strand.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

function addToCart(User $customer, Product $product, int $quantity = 1): void
{
    test()->actingAs($customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => $quantity])
        ->assertRedirect();
}

/** Freeze the current cart into a pending, card-payable session. */
function pendingSession(User $customer): CheckoutSession
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
    );
}

it('freezes the basket and its prices when checkout starts, charging nothing yet', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    addToCart($this->customer, $product, 2);

    $session = pendingSession($this->customer);

    // Delivery is charged on every order now — free delivery only exists
    // where an admin has set a threshold on the rates screen.
    expect($session->status)->toBe('pending')
        ->and($session->paystack_reference)->not->toBeNull()
        ->and($session->shipping_fee_kobo)->toBe(1_500_00)
        ->and($session->total_amount_kobo)->toBe(101_500_00)
        ->and($session->items_snapshot)->toHaveCount(1)
        ->and($session->items_snapshot[0]['unit_price_kobo'])->toBe(50_000_00)
        // No orders until the money actually arrives.
        ->and(Order::query()->count())->toBe(0);
});

it('creates one order per unit across vendors when the payment clears', function () {
    $productA = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    $productB = Product::factory()->approved()->create(['price_kobo' => 30_000_00, 'stock_quantity' => 5]);

    addToCart($this->customer, $productA);
    addToCart($this->customer, $productB);

    $session = pendingSession($this->customer);
    app(CartCheckoutService::class)->completePaidSession($session);

    $orders = Order::query()->where('customer_id', $this->customer->id)->get();

    expect($orders)->toHaveCount(2)
        ->and($orders->pluck('vendor_id')->unique())->toHaveCount(2)
        ->and($orders->pluck('checkout_session_id')->unique())->toHaveCount(1)
        ->and($orders->every(fn (Order $order) => $order->delivery_address === '12 Marina Road'))->toBeTrue()
        ->and($orders->every(fn (Order $order) => $order->recipient_phone === '08031234567'))->toBeTrue()
        ->and($session->refresh()->status)->toBe('paid');

    // Every purchased item left the cart.
    $cartId = Cart::query()->where('user_id', $this->customer->id)->value('id');
    expect(CartItem::query()->where('cart_id', $cartId)->count())->toBe(0);
});

it('fans a quantity greater than one into that many orders at the frozen unit price', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    addToCart($this->customer, $product, 3);

    $session = pendingSession($this->customer);

    // Vendor reprices while the shopper is on Paystack.
    $product->forceFill(['price_kobo' => 45_000_00])->save();

    app(CartCheckoutService::class)->completePaidSession($session);

    $orders = Order::query()->where('customer_id', $this->customer->id)->get();

    expect($orders)->toHaveCount(3)
        // They pay what they were shown, not the new price.
        ->and($orders->every(fn (Order $order) => $order->locked_price_kobo === 20_000_00))->toBeTrue();
});

it('re-checks availability when the payment clears and leaves the failed item in the cart', function () {
    $available = Product::factory()->approved()->create(['price_kobo' => 40_000_00, 'stock_quantity' => 5]);
    $goesOutOfStock = Product::factory()->approved()->create(['price_kobo' => 25_000_00, 'stock_quantity' => 5]);

    addToCart($this->customer, $available);
    addToCart($this->customer, $goesOutOfStock);

    $session = pendingSession($this->customer);

    // Sells out while the shopper is paying.
    $goesOutOfStock->forceFill(['stock_quantity' => 0])->save();

    $result = app(CartCheckoutService::class)->completePaidSession($session);

    expect($result['skippedProductNames'])->toHaveCount(1);

    $orders = Order::query()->where('customer_id', $this->customer->id)->get();
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->product_id)->toBe($available->id);

    // The unavailable item is still sitting in the cart.
    expect(CartItem::query()->where('product_id', $goesOutOfStock->id)->exists())->toBeTrue();
});

it('skips a campaign line whose stock cap ran out before the webhook completes, without aborting the whole checkout', function () {
    $available = Product::factory()->approved()->create(['price_kobo' => 40_000_00, 'stock_quantity' => 5]);
    $dealProduct = Product::factory()->approved()->create(['price_kobo' => 25_000_00, 'stock_quantity' => 5]);

    $campaign = Campaign::query()->create([
        'name' => 'Flash Sale',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
    ]);
    $campaign->products()->attach($dealProduct, [
        'sale_price_kobo' => 20_000_00,
        'stock_cap' => 1,
        'sold_quantity' => 0,
    ]);

    addToCart($this->customer, $available);
    addToCart($this->customer, $dealProduct);

    $session = pendingSession($this->customer);

    // Another shopper's webhook lands first and exhausts the campaign cap
    // while this one is still on Paystack.
    DB::table('campaign_products')
        ->where('campaign_id', $campaign->id)
        ->where('product_id', $dealProduct->id)
        ->update(['sold_quantity' => 1]);

    $result = app(CartCheckoutService::class)->completePaidSession($session);

    expect($result['skippedProductNames'])->toHaveCount(1);

    $orders = Order::query()->where('customer_id', $this->customer->id)->get();
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->product_id)->toBe($available->id)
        // The failed reservation did not roll back the rest of the
        // transaction — the session still clears to paid.
        ->and($session->refresh()->status)->toBe('paid');

    // The exhausted campaign's stock counter was not double-incremented by
    // the lost reservation attempt.
    expect((int) DB::table('campaign_products')
        ->where('campaign_id', $campaign->id)
        ->where('product_id', $dealProduct->id)
        ->value('sold_quantity'))->toBe(1);
});

it('refuses to start a checkout when nothing in the cart is available', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);
    addToCart($this->customer, $product);

    $product->forceFill(['status' => ProductStatus::Delisted])->save();

    pendingSession($this->customer);
})->throws(ValidationException::class);

it('does not raise the orders twice when the same webhook is replayed', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    addToCart($this->customer, $product, 2);

    $session = pendingSession($this->customer);

    app(CartCheckoutService::class)->completePaidSession($session);
    app(CartCheckoutService::class)->completePaidSession($session->refresh());

    expect(Order::query()->where('customer_id', $this->customer->id)->count())->toBe(2);
});

it('never exposes the delivery address on the vendor orders screen', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    $vendorUser = $product->vendor->user;
    $vendorUser->assignRole('Vendor');
    // ->approved() approves the listing, not the seller behind it, and only
    // an approved vendor can open the Vendor Center.
    $product->vendor->update(['status' => VendorStatus::Approved]);

    addToCart($this->customer, $product);
    app(CartCheckoutService::class)->completePaidSession(pendingSession($this->customer));

    $response = $this->actingAs($vendorUser)
        ->get('http://'.config('app.vendor_domain').'/orders')
        ->assertOk();

    $serialized = json_encode($response->viewData('page')['props']['orders']);

    expect($serialized)->not->toContain($this->customer->name)
        ->and($serialized)->not->toContain('12 Marina Road')
        ->and($serialized)->not->toContain('08031234567');
});
