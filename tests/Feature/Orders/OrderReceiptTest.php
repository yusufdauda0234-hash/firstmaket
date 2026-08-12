<?php

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\OrderReceipt;
use App\Modules\Orders\Notifications\ReceiptIssuedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The customer's receipt for a checkout.
 *
 * One document per payment, numbered, immutable, and never issued twice for
 * the same money however many times the webhook fires.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

function receiptAddToCart(User $customer, Product $product, int $quantity = 1): void
{
    test()->actingAs($customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => $quantity])
        ->assertRedirect();
}

function receiptPendingSession(User $customer): CheckoutSession
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

it('issues a numbered receipt when a checkout is paid', function () {
    Notification::fake();

    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    receiptAddToCart($this->customer, $product, 2);

    $session = receiptPendingSession($this->customer);
    app(CartCheckoutService::class)->completePaidSession($session);

    $receipt = OrderReceipt::query()->sole();

    expect($receipt->customer_id)->toBe($this->customer->id)
        ->and($receipt->checkout_session_id)->toBe($session->id)
        ->and($receipt->receipt_number)->toMatch('/^FM-\d{4}-\d{6}$/')
        ->and($receipt->subtotal_kobo)->toBe(100_000_00)
        ->and($receipt->shipping_kobo)->toBe(1_500_00)
        ->and($receipt->total_kobo)->toBe(101_500_00)
        ->and($receipt->payment_reference)->toBe($session->paystack_reference);
});

it('folds the units of one product into a single line', function () {
    Notification::fake();

    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    receiptAddToCart($this->customer, $product, 3);

    app(CartCheckoutService::class)->completePaidSession(receiptPendingSession($this->customer));

    $lines = OrderReceipt::query()->sole()->items_snapshot;

    // Three orders — one per unit, which is right for delivery — but nobody
    // wants a receipt listing the same kettle three times.
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['quantity'])->toBe(3)
        ->and($lines[0]['unitPriceKobo'])->toBe(20_000_00)
        ->and($lines[0]['lineTotalKobo'])->toBe(60_000_00);
});

it('puts a multi-vendor basket on one receipt', function () {
    Notification::fake();

    $productA = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    $productB = Product::factory()->approved()->create(['price_kobo' => 30_000_00, 'stock_quantity' => 5]);

    receiptAddToCart($this->customer, $productA);
    receiptAddToCart($this->customer, $productB);

    app(CartCheckoutService::class)->completePaidSession(receiptPendingSession($this->customer));

    // Two vendors, two parcels — but one payment, so one document.
    expect(OrderReceipt::query()->count())->toBe(1)
        ->and(OrderReceipt::query()->sole()->items_snapshot)->toHaveCount(2);
});

it('never issues a second receipt for a replayed payment', function () {
    Notification::fake();

    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    receiptAddToCart($this->customer, $product);

    $session = receiptPendingSession($this->customer);

    app(CartCheckoutService::class)->completePaidSession($session);
    app(CartCheckoutService::class)->completePaidSession($session->refresh());

    expect(OrderReceipt::query()->count())->toBe(1);
});

it('emails the receipt to the customer', function () {
    Notification::fake();

    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    receiptAddToCart($this->customer, $product);

    app(CartCheckoutService::class)->completePaidSession(receiptPendingSession($this->customer));

    Notification::assertSentTo($this->customer, ReceiptIssuedNotification::class);
    expect(OrderReceipt::query()->sole()->emailed_at)->not->toBeNull();
});

it('shows the receipt to its owner', function () {
    Notification::fake();

    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    receiptAddToCart($this->customer, $product);
    app(CartCheckoutService::class)->completePaidSession(receiptPendingSession($this->customer));

    $receipt = OrderReceipt::query()->sole();

    $this->actingAs($this->customer)
        ->get(route('receipts.show', $receipt->uuid))
        ->assertOk();

    $this->actingAs($this->customer)
        ->get(route('receipts.index'))
        ->assertOk();
});

it('hides a receipt from everybody else', function () {
    Notification::fake();

    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    receiptAddToCart($this->customer, $product);
    app(CartCheckoutService::class)->completePaidSession(receiptPendingSession($this->customer));

    $receipt = OrderReceipt::query()->sole();
    $stranger = User::factory()->create();

    // It carries a name, a phone number and a home address. The unguessable
    // uuid is not the access control.
    $this->actingAs($stranger)
        ->get(route('receipts.show', $receipt->uuid))
        ->assertForbidden();
});
