<?php

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\OrderStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * "My Orders" lists one card per purchase.
 *
 * Internally an order is a single unit — that is what a vendor packs and a
 * courier carries — so one payment across two vendors makes several rows.
 * Listing those raw made a single purchase look like unrelated ones.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

/** One payment. Orders point at it, which is how they get grouped. */
function purchase(User $customer): CheckoutSession
{
    return CheckoutSession::query()->create([
        'user_id' => $customer->id,
        'total_amount_kobo' => 0,
        'delivery_address' => '12 Marina Road',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'status' => 'paid',
    ]);
}

/** Orders for one product, all on the same purchase. */
function ordersOnSession(
    User $customer,
    Product $product,
    CheckoutSession $session,
    int $units = 1,
    ?OrderStatus $status = null,
): void {
    foreach (range(1, $units) as $ignored) {
        Order::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $product->vendor_id,
            'product_id' => $product->id,
            'checkout_session_id' => $session->id,
            'status' => $status ?? OrderStatus::Pending,
            'locked_price_kobo' => $product->price_kobo,
        ]);
    }
}

it('shows one card for a purchase spanning two vendors', function () {
    $first = Product::factory()->approved()->create(['price_kobo' => 30_000_00]);
    $second = Product::factory()->approved()->create(['price_kobo' => 2_000_00]);

    $session = purchase($this->customer);
    ordersOnSession($this->customer, $first, $session);
    ordersOnSession($this->customer, $second, $session);

    $this->actingAs($this->customer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page
            ->has('groups', 1)
            ->has('groups.0.items', 2)
            ->where('groups.0.vendorCount', 2)
            ->where('groups.0.totalKobo', 32_000_00));
});

it('keeps separate purchases apart', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);

    ordersOnSession($this->customer, $product, purchase($this->customer));
    ordersOnSession($this->customer, $product, purchase($this->customer));

    $this->actingAs($this->customer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('groups', 2));
});

it('collapses several units of one product into a quantity', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);

    ordersOnSession($this->customer, $product, purchase($this->customer), units: 3);

    $this->actingAs($this->customer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page
            ->has('groups', 1)
            ->has('groups.0.items', 1)
            ->where('groups.0.items.0.quantity', 3)
            ->where('groups.0.items.0.lineTotalKobo', 15_000_00)
            ->where('groups.0.parcelCount', 3));
});

it('reports one status when every parcel agrees', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);

    ordersOnSession($this->customer, $product, purchase($this->customer), units: 2, status: OrderStatus::Shipped);

    $this->actingAs($this->customer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.status.value', 'shipped')
            ->where('groups.0.status.mixed', false));
});

it('reports the least advanced parcel when they disagree', function () {
    $first = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);
    $second = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);

    $session = purchase($this->customer);
    ordersOnSession($this->customer, $first, $session, status: OrderStatus::Delivered);
    ordersOnSession($this->customer, $second, $session, status: OrderStatus::Processing);

    // Calling the whole purchase "Delivered" while a box is still being
    // packed is the failure worth avoiding.
    $this->actingAs($this->customer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.status.value', 'processing')
            ->where('groups.0.status.mixed', true));
});

it('does not treat a rejected parcel as the most advanced', function () {
    $first = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);
    $second = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);

    // VendorRejected sits after Delivered in the enum, so enum order alone
    // would have picked Delivered as the summary.
    $session = purchase($this->customer);
    ordersOnSession($this->customer, $first, $session, status: OrderStatus::Delivered);
    ordersOnSession($this->customer, $second, $session, status: OrderStatus::VendorRejected);

    $this->actingAs($this->customer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->where('groups.0.status.value', 'vendor_rejected'));
});

it('keeps orders with no checkout session separate', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);

    Order::factory()->count(2)->create([
        'customer_id' => $this->customer->id,
        'vendor_id' => $product->vendor_id,
        'product_id' => $product->id,
        'checkout_session_id' => null,
        'locked_price_kobo' => $product->price_kobo,
    ]);

    // Grouping on a null key would collapse unrelated orders into one card.
    $this->actingAs($this->customer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('groups', 2));
});

it('shows nobody else their orders', function () {
    $stranger = User::factory()->create();
    $stranger->assignRole('Customer');
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);

    ordersOnSession($stranger, $product, purchase($stranger));

    $this->actingAs($this->customer)
        ->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->has('groups', 0));
});
