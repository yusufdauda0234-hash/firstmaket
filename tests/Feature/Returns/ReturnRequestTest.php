<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ReturnReason;
use App\Shared\Enums\ReturnStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2E: opening a return, and the rules that decide whether one may be
 * opened at all.
 *
 * These mirror the policy printed on every product page — seven days from
 * delivery, who pays the return delivery, and the categories that can only
 * come back faulty. The point of the phase is that those two things are the
 * same rules, so the tests assert the published wording.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->customer = User::factory()->create();
    $this->returns = app(ReturnService::class);
});

function returnableOrder(User $customer, array $overrides = [], array $categoryOverrides = []): Order
{
    $category = Category::factory()->create($categoryOverrides);
    $vendor = VendorProfile::factory()->create();
    $product = Product::factory()->approved()->create([
        'category_id' => $category->id,
        'vendor_id' => $vendor->id,
    ]);

    return Order::query()->create(array_merge([
        'customer_id' => $customer->id,
        'vendor_id' => $vendor->id,
        'product_id' => $product->id,
        'delivery_address' => '1 Test Street',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'status' => OrderStatus::Delivered,
        'locked_price_kobo' => 500_000,
        'commission_rate_percent' => '10.00',
        'commission_source' => 'default',
        'commission_amount_kobo' => 50_000,
        'vendor_earning_amount_kobo' => 450_000,
        'delivered_at' => now()->subDay(),
    ], $overrides));
}

it('opens a return inside the window and snapshots the policy onto it', function () {
    $order = returnableOrder($this->customer);

    $return = $this->returns->open($this->customer, $order, ReturnReason::Damaged, 'Screen cracked.');

    expect($return->status)->toBe(ReturnStatus::Requested)
        ->and($return->policy_window_days)->toBe(7)
        // Damaged is our fault, so we pay to get it back.
        ->and($return->return_delivery_paid_by)->toBe('platform')
        ->and($return->required_unopened)->toBeFalse()
        ->and($return->refundable_kobo)->toBe(500_000);
});

it('makes the customer pay return delivery when they simply changed their mind', function () {
    $order = returnableOrder($this->customer);

    $return = $this->returns->open($this->customer, $order, ReturnReason::ChangedMind);

    expect($return->return_delivery_paid_by)->toBe('customer')
        ->and($return->required_unopened)->toBeTrue();
});

it('refuses a return once the seven-day window has closed', function () {
    $order = returnableOrder($this->customer, ['delivered_at' => now()->subDays(8)]);

    expect(fn () => $this->returns->open($this->customer, $order, ReturnReason::Damaged))
        ->toThrow(ValidationException::class);
});

it('measures the window from delivery, not from when the order was placed', function () {
    // Placed a month ago, delivered yesterday: still returnable.
    $order = returnableOrder($this->customer, [
        'created_at' => now()->subMonth(),
        'delivered_at' => now()->subDay(),
    ]);

    expect($this->returns->open($this->customer, $order, ReturnReason::Faulty))
        ->status->toBe(ReturnStatus::Requested);
});

it('refuses a return on an order that was never delivered', function () {
    $order = returnableOrder($this->customer, [
        'status' => OrderStatus::Shipped,
        'delivered_at' => null,
    ]);

    expect(fn () => $this->returns->open($this->customer, $order, ReturnReason::Damaged))
        ->toThrow(ValidationException::class);
});

it('blocks a change-of-mind return on an excluded category but allows a faulty one', function () {
    $order = returnableOrder($this->customer, [], ['returnable_on_change_of_mind' => false]);

    // Perishables, underwear, pierced jewellery: no change-of-mind returns...
    expect(fn () => $this->returns->open($this->customer, $order, ReturnReason::ChangedMind))
        ->toThrow(ValidationException::class);

    // ...but a faulty one must still be accepted.
    expect($this->returns->open($this->customer, $order, ReturnReason::Faulty))
        ->status->toBe(ReturnStatus::Requested);
});

it('will not let one customer open a return on another customer order', function () {
    $order = returnableOrder($this->customer);
    $stranger = User::factory()->create();

    expect(fn () => $this->returns->open($stranger, $order, ReturnReason::Damaged))
        ->toThrow(ValidationException::class);
});

it('refuses a second return while one is already open on the order', function () {
    $order = returnableOrder($this->customer);

    $this->returns->open($this->customer, $order, ReturnReason::Damaged);

    expect(fn () => $this->returns->open($this->customer, $order, ReturnReason::Faulty))
        ->toThrow(ValidationException::class);
});

it('lets the customer open a fresh return after an earlier one was rejected', function () {
    $order = returnableOrder($this->customer);
    $admin = User::factory()->create();

    $first = $this->returns->open($this->customer, $order, ReturnReason::ChangedMind);
    $this->returns->reject($admin, $first, 'Packaging was opened and the seal broken.');

    expect($this->returns->open($this->customer, $order, ReturnReason::Faulty))
        ->status->toBe(ReturnStatus::Requested);
});

it('refunds the price actually paid, not the list price, when a promo was used', function () {
    $order = returnableOrder($this->customer, ['promo_discount_kobo' => 100_000]);

    // Otherwise a discount code turns a return into a profit.
    expect($this->returns->open($this->customer, $order, ReturnReason::Damaged))
        ->refundable_kobo->toBe(400_000);
});

it('records every state change with who made it', function () {
    $order = returnableOrder($this->customer);
    $admin = User::factory()->create();

    $return = $this->returns->open($this->customer, $order, ReturnReason::Damaged);
    $this->returns->approve($admin, $return, 'Photos are clear.');

    $events = $return->refresh()->events()->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->to_status)->toBe(ReturnStatus::Requested)
        ->and($events[0]->actor_id)->toBe($this->customer->id)
        ->and($events[1]->to_status)->toBe(ReturnStatus::Approved)
        ->and($events[1]->actor_id)->toBe($admin->id);
});

it('lets a customer cancel early but not once it is on its way back', function () {
    $order = returnableOrder($this->customer);
    $admin = User::factory()->create();

    $return = $this->returns->open($this->customer, $order, ReturnReason::Damaged);
    $this->returns->approve($admin, $return);
    $this->returns->markInTransit($this->customer, $return);

    expect(fn () => $this->returns->cancel($this->customer, $return))
        ->toThrow(ValidationException::class);
});

it('sends a contested return to an admin rather than letting the vendor close it', function () {
    $order = returnableOrder($this->customer);
    $admin = User::factory()->create();
    $vendorUser = User::factory()->create();

    $return = $this->returns->open($this->customer, $order, ReturnReason::NotAsDescribed);
    $this->returns->approve($admin, $return);
    $this->returns->markInTransit($this->customer, $return);
    $this->returns->markReceived($vendorUser, $return);

    $this->returns->contest($vendorUser, $return, 'Item came back with parts missing.');

    // Disputed, not rejected: the vendor loses the sale, so the vendor does
    // not get to decide the outcome.
    expect($return->refresh()->status)->toBe(ReturnStatus::Disputed);
});
