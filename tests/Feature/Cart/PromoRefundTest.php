<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Models\PromoRedemption;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PreparationService;
use App\Modules\Savings\Models\SavingsTransaction;
use App\Shared\Enums\OrderStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * What happens to a discount when the order it paid for falls through.
 *
 * Two rules, both about money not being created out of nothing: the customer
 * gets back what they paid, not what the item was worth; and the code comes
 * back only when they got nothing at all for it.
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

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');

    $this->prep = app(PreparationService::class);
});

/** A paid checkout with a discount already on it. */
function discountedPurchase(User $customer, array $products): void
{
    PromoCode::query()->create([
        'code' => 'REFUND10',
        'type' => 'percent',
        'percent_off' => '10.00',
        'max_discount_kobo' => 100_000_00,
        'is_active' => true,
    ]);

    foreach ($products as $product) {
        test()->actingAs($customer)
            ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1])
            ->assertRedirect();
    }

    $session = app(CartCheckoutService::class)->startCardCheckout(
        $customer,
        app(CartService::class)->lines($customer),
        [
            'recipient_name' => 'Yakubu Dauda',
            'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ],
        'REFUND10',
    );

    app(CartCheckoutService::class)->completePaidSession($session);
}

/** Walk an order to Vendor Rejected, which is the only state admin can refund. */
function rejectOrder(Order $order, string $reason = 'Out of stock'): Order
{
    $vendorUser = $order->vendor->user;

    app(OrderService::class)
        ->transition(test()->admin, $order, OrderStatus::Processing, 'Payment confirmed');

    return app(PreparationService::class)->reject($vendorUser, $order->fresh(), $reason);
}

it('refunds what the customer paid, not the full price', function () {
    discountedPurchase($this->customer, [
        Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]),
    ]);

    $order = Order::query()->firstOrFail();
    expect($order->promo_discount_kobo)->toBe(5_000_00);

    $this->prep->resolveRejectionToSavings($this->admin, rejectOrder($order));

    // ₦50,000 item, ₦5,000 of it paid by the promotion — the customer is owed
    // the ₦45,000 they actually parted with, not the ₦50,000 it was worth.
    $credit = SavingsTransaction::query()
        ->where('reference', 'REFUND-ORDER-'.$order->uuid)
        ->firstOrFail();

    expect($credit->amount_kobo)->toBe(45_000_00);
});

it('gives the code back when the whole checkout falls through', function () {
    discountedPurchase($this->customer, [
        Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]),
    ]);

    $this->prep->resolveRejectionToSavings($this->admin, rejectOrder(Order::query()->firstOrFail()));

    expect(PromoRedemption::query()->whereNotNull('released_at')->count())->toBe(1);
});

it('keeps the code spent while any of the basket still stands', function () {
    discountedPurchase($this->customer, [
        Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]),
        Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]),
    ]);

    // Only one of the two vendors could not supply. The customer still has
    // the discount on the other item, so the code is still spent.
    $this->prep->resolveRejectionToSavings($this->admin, rejectOrder(Order::query()->firstOrFail()));

    expect(PromoRedemption::query()->whereNotNull('released_at')->count())->toBe(0);
});

it('releases only once the last surviving order is gone', function () {
    discountedPurchase($this->customer, [
        Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]),
        Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]),
    ]);

    foreach (Order::query()->get() as $order) {
        $this->prep->resolveRejectionToSavings($this->admin, rejectOrder($order->fresh()));
    }

    expect(PromoRedemption::query()->whereNotNull('released_at')->count())->toBe(1);
});
