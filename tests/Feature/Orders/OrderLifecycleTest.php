<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\CategoryCommissionRate;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Notifications\OrderStatusNotification;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PreparationService;
use App\Modules\Savings\Models\OpenSaving;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Savings\Services\PlanService;
use App\Modules\Vendor\Notifications\ItemSoldNotification;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\PlanPaymentMode;
use App\Shared\Enums\PlanStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 6 QA (docs/FirstMaket_Implementation_Plan.md): address capture
 * only after full funding, vendor notified without customer identity, admin
 * confirmation gate, the delivery chain, SLA flagging, and the
 * refund-to-savings rejection path.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');

    $this->product = Product::factory()->approved()->create(['price_kobo' => 100_000_00]);
    $this->vendorUser = $this->product->vendor->user;
    $this->vendorUser->assignRole('Vendor');
});

/** Fund the wallet and pay at once so the plan is Ready for Delivery. */
function readyPlan(User $customer, Product $product): ProductTargetPlan
{
    app(WalletService::class)->creditDeposit($customer, $product->price_kobo, 'TEST-DEP-'.fake()->unique()->uuid());

    return app(PlanService::class)->payAtOnce($customer, $product);
}

function placeOrder(User $customer, ProductTargetPlan $plan): Order
{
    return app(OrderService::class)->createFromPlan(
        customer: $customer,
        plan: $plan,
        deliveryAddress: '12 Marina Road',
        state: 'Lagos',
        lga: 'Eti-Osa',
    );
}

it('refuses delivery address capture before the plan is fully funded', function () {
    app(WalletService::class)->creditDeposit($this->customer, 10_000_00, 'TEST-DEP-'.fake()->unique()->uuid());
    $plan = app(PlanService::class)->create(
        $this->customer, $this->product, PlanPaymentMode::Schedule,
        PlanCadence::Weekly, 10_000_00,
    );

    placeOrder($this->customer, $plan);
})->throws(ValidationException::class);

it('creates the order from a ready plan with commission snapshot and completes the plan', function () {
    // Category rate 15% set before the order.
    CategoryCommissionRate::query()->create([
        'category_id' => $this->product->category_id,
        'rate_percent' => '15.00',
        'effective_from' => now()->subHour(),
        'set_by' => $this->admin->id,
        'created_at' => now(),
    ]);

    $plan = readyPlan($this->customer, $this->product);
    $order = placeOrder($this->customer, $plan);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->locked_price_kobo)->toBe(100_000_00)
        ->and($order->commission_amount_kobo)->toBe(15_000_00)
        ->and($order->vendor_earning_amount_kobo)->toBe(85_000_00)
        ->and($plan->refresh()->status)->toBe(PlanStatus::Completed);

    // Later rate change never alters the snapshot.
    CategoryCommissionRate::query()->create([
        'category_id' => $this->product->category_id,
        'rate_percent' => '5.00',
        'effective_from' => now(),
        'set_by' => $this->admin->id,
        'created_at' => now(),
    ]);
    expect($order->refresh()->commission_amount_kobo)->toBe(15_000_00);

    // Vendor got the "item sold" notification.
    Notification::assertSentTo($this->vendorUser, ItemSoldNotification::class);
});

it('never exposes customer identity or address on the vendor orders screen', function () {
    $plan = readyPlan($this->customer, $this->product);
    placeOrder($this->customer, $plan);

    $response = $this->actingAs($this->vendorUser)
        ->get('http://'.config('app.vendor_domain').'/orders')
        ->assertOk();

    $serialized = json_encode($response->viewData('page')['props']['orders']);

    expect($serialized)->not->toContain($this->customer->name)
        ->and($serialized)->not->toContain('12 Marina Road')
        ->and($serialized)->not->toContain('Eti-Osa');
});

it('requires admin confirmation before the vendor can prepare', function () {
    $plan = readyPlan($this->customer, $this->product);
    $order = placeOrder($this->customer, $plan);

    // Vendor cannot mark ready while Pending.
    expect(fn () => app(PreparationService::class)->markReadyForPickup($this->vendorUser, $order))
        ->toThrow(ValidationException::class);

    app(OrderService::class)->confirm($this->admin, $order);

    expect($order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->prepare_due_at)->not->toBeNull();
});

it('walks the full delivery chain and notifies the customer at every step', function () {
    $plan = readyPlan($this->customer, $this->product);
    $order = placeOrder($this->customer, $plan);

    app(OrderService::class)->confirm($this->admin, $order);
    app(PreparationService::class)->markReadyForPickup($this->vendorUser, $order->refresh());

    $logistics = User::factory()->create();
    $logistics->assignRole('Logistics Personnel');
    app(DeliveryService::class)->assign($this->admin, $order->refresh(), $logistics);

    $delivery = app(DeliveryService::class);
    foreach ([OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::OutForDelivery, OrderStatus::Delivered] as $step) {
        $delivery->updateStatus($logistics, $order->refresh(), $step);
    }

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->delivered_at)->not->toBeNull();

    // Placed + confirmed + ready + 4 logistics steps = 7 status notifications.
    Notification::assertSentToTimes($this->customer, OrderStatusNotification::class, 7);

    // Every transition is in the status event trail.
    expect($order->statusEvents()->pluck('new_status')->all())->toBe([
        'pending', 'processing', 'ready_for_pickup', 'packed', 'shipped', 'out_for_delivery', 'delivered',
    ]);
});

it('blocks an unassigned logistics user but allows the assigned one', function () {
    $plan = readyPlan($this->customer, $this->product);
    $order = placeOrder($this->customer, $plan);
    app(OrderService::class)->confirm($this->admin, $order);
    app(PreparationService::class)->markReadyForPickup($this->vendorUser, $order->refresh());

    $assigned = User::factory()->create();
    $assigned->assignRole('Logistics Personnel');
    $stranger = User::factory()->create();
    $stranger->assignRole('Logistics Personnel');

    app(DeliveryService::class)->assign($this->admin, $order->refresh(), $assigned);

    expect(fn () => app(DeliveryService::class)->updateStatus($stranger, $order->refresh(), OrderStatus::Packed))
        ->toThrow(ValidationException::class);

    app(DeliveryService::class)->updateStatus($assigned, $order->refresh(), OrderStatus::Packed);
    expect($order->refresh()->status)->toBe(OrderStatus::Packed);
});

it('flags an overdue preparation to admin exactly once', function () {
    $plan = readyPlan($this->customer, $this->product);
    $order = placeOrder($this->customer, $plan);
    app(OrderService::class)->confirm($this->admin, $order);

    $order->forceFill(['prepare_due_at' => now()->subHour()])->save();

    $this->artisan('orders:flag-overdue-preparation')->assertSuccessful();
    $this->artisan('orders:flag-overdue-preparation')->assertSuccessful();

    expect($order->preparationEvents()->where('status', 'sla_breached')->count())->toBe(1);
});

it('resolves a vendor rejection by refunding the full price to Open Savings — never cash', function () {
    $plan = readyPlan($this->customer, $this->product);
    $order = placeOrder($this->customer, $plan);
    app(OrderService::class)->confirm($this->admin, $order);

    app(PreparationService::class)->reject($this->vendorUser, $order->refresh(), 'Out of stock');
    expect($order->refresh()->status)->toBe(OrderStatus::VendorRejected);

    app(PreparationService::class)->resolveRejectionToSavings($this->admin, $order->refresh());

    $pot = OpenSaving::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($pot->balance_kobo)->toBe(100_000_00)
        // Wallet untouched — no cash path exists.
        ->and(app(WalletService::class)->getOrCreate($this->customer)->balance_kobo)->toBe(0);
});

it('keeps the logistics role out of catalog and pricing management', function () {
    $logistics = User::factory()->staff()->create();
    $logistics->forceFill(['two_factor_confirmed_at' => now()])->save();
    $logistics->assignRole('Logistics Personnel');

    $this->actingAs($logistics)
        ->get('http://'.config('app.admin_domain').'/products')
        ->assertForbidden();
});
