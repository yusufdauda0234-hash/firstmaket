<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Orders\Models\CommissionRule;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Notifications\OrderStatusNotification;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PreparationService;
use App\Modules\Savings\Models\Savings;
use App\Modules\Savings\Models\SavingsTransaction;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Modules\Savings\Services\SavingsService;
use App\Modules\Vendor\Notifications\ItemSoldNotification;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\SavingsGoalStatus;
use App\Shared\Enums\SavingsTransactionType;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 6 QA (docs/FirstMaket_Implementation_Plan.md): a savings goal
 * cannot be bought before the balance covers it, the vendor is notified
 * without customer identity, the admin confirmation gate, the delivery
 * chain, SLA flagging, and the refund-to-savings rejection path.
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
    // ->approved() approves the listing, not the seller behind it, and only
    // an approved vendor can open the Vendor Center.
    $this->product->vendor->update(['status' => VendorStatus::Approved]);
});

it('refuses to collect a plan before it is fully paid', function () {
    $plan = testPlan($this->customer, $this->product);

    // A tenth of the price in — nowhere near covered.
    app(SavingsGoalService::class)->recordPayment($this->customer, $plan, 10_000_00, reference: 'TEST-PART');

    app(SavingsGoalService::class)->fulfil($this->customer, $plan->refresh());
})->throws(ValidationException::class);

it('creates the order from a funded goal with a commission snapshot and marks the goal fulfilled', function () {
    // Category rule at 15%, covering any price.
    CommissionRule::query()->create([
        'scope_type' => 'category',
        'scope_id' => $this->product->category_id,
        'rate_percent' => '15.00',
        'is_active' => true,
    ]);

    $goal = testPaidPlan($this->customer, $this->product);
    $order = app(SavingsGoalService::class)->fulfil($this->customer, $goal)->first();

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->locked_price_kobo)->toBe(100_000_00)
        ->and($order->commission_amount_kobo)->toBe(15_000_00)
        ->and($order->vendor_earning_amount_kobo)->toBe(85_000_00)
        ->and($goal->refresh()->status)->toBe(SavingsGoalStatus::Fulfilled);

    // Later rule change never alters the snapshot.
    CommissionRule::query()->create([
        'scope_type' => 'category',
        'scope_id' => $this->product->category_id,
        'rate_percent' => '5.00',
        'is_active' => true,
    ]);
    expect($order->refresh()->commission_amount_kobo)->toBe(15_000_00);

    // Vendor got the "item sold" notification.
    Notification::assertSentTo($this->vendorUser, ItemSoldNotification::class);
});

it('never exposes customer identity or address on the vendor orders screen', function () {
    $goal = testPaidPlan($this->customer, $this->product);
    app(SavingsGoalService::class)->fulfil($this->customer, $goal)->first();

    $response = $this->actingAs($this->vendorUser)
        ->get('http://'.config('app.vendor_domain').'/orders')
        ->assertOk();

    $serialized = json_encode($response->viewData('page')['props']['orders']);

    expect($serialized)->not->toContain($this->customer->name)
        ->and($serialized)->not->toContain('12 Marina Road')
        ->and($serialized)->not->toContain('Eti-Osa');
});

it('requires admin confirmation before the vendor can prepare', function () {
    $goal = testPaidPlan($this->customer, $this->product);
    $order = app(SavingsGoalService::class)->fulfil($this->customer, $goal)->first();

    // Vendor cannot mark ready while Pending.
    expect(fn () => app(PreparationService::class)->markReadyForPickup($this->vendorUser, $order))
        ->toThrow(ValidationException::class);

    app(OrderService::class)->confirm($this->admin, $order);

    expect($order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->prepare_due_at)->not->toBeNull();
});

it('walks the full delivery chain and notifies the customer at every step', function () {
    $goal = testPaidPlan($this->customer, $this->product);
    $order = app(SavingsGoalService::class)->fulfil($this->customer, $goal)->first();

    app(OrderService::class)->confirm($this->admin, $order);
    app(PreparationService::class)->markReadyForPickup($this->vendorUser, $order->refresh());

    $logistics = User::factory()->create();
    $logistics->assignRole('Logistics Personnel');

    $shipment = $order->refresh()->shipment;
    app(DeliveryService::class)->assign($this->admin, $shipment, $logistics);
    walkParcel($logistics, $shipment->fresh(), ShipmentStatus::Delivered);

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
    $goal = testPaidPlan($this->customer, $this->product);
    $order = app(SavingsGoalService::class)->fulfil($this->customer, $goal)->first();
    app(OrderService::class)->confirm($this->admin, $order);
    app(PreparationService::class)->markReadyForPickup($this->vendorUser, $order->refresh());

    $assigned = User::factory()->create();
    $assigned->assignRole('Logistics Personnel');
    $stranger = User::factory()->create();
    $stranger->assignRole('Logistics Personnel');

    $shipment = $order->refresh()->shipment;
    app(DeliveryService::class)->assign($this->admin, $shipment, $assigned);

    expect(fn () => app(DeliveryService::class)->advance($stranger, $shipment->fresh(), ShipmentStatus::Packed))
        ->toThrow(ValidationException::class);

    app(DeliveryService::class)->advance($assigned, $shipment->fresh(), ShipmentStatus::Packed);
    expect($order->refresh()->status)->toBe(OrderStatus::Packed);
});

it('flags an overdue preparation to admin exactly once', function () {
    $goal = testPaidPlan($this->customer, $this->product);
    $order = app(SavingsGoalService::class)->fulfil($this->customer, $goal)->first();
    app(OrderService::class)->confirm($this->admin, $order);

    $order->forceFill(['prepare_due_at' => now()->subHour()])->save();

    $this->artisan('orders:flag-overdue-preparation')->assertSuccessful();
    $this->artisan('orders:flag-overdue-preparation')->assertSuccessful();

    expect($order->preparationEvents()->where('status', 'sla_breached')->count())->toBe(1);
});

it('resolves a vendor rejection by refunding the full price to savings — never cash', function () {
    $goal = testPaidPlan($this->customer, $this->product);
    $order = app(SavingsGoalService::class)->fulfil($this->customer, $goal)->first();
    app(OrderService::class)->confirm($this->admin, $order);

    app(PreparationService::class)->reject($this->vendorUser, $order->refresh(), 'Out of stock');
    expect($order->refresh()->status)->toBe(OrderStatus::VendorRejected);

    // Nothing is held on the customer before the refund lands.
    expect(app(SavingsService::class)->creditKobo($this->customer))->toBe(0);

    app(PreparationService::class)->resolveRejectionToSavings($this->admin, $order->refresh());

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        // The money comes back to savings, ready for another product —
        // there is no cash path for it to take instead.
        ->and(app(SavingsService::class)->creditKobo($this->customer))->toBe(100_000_00)
        ->and(SavingsTransaction::query()
            ->where('user_id', $this->customer->id)
            ->where('type', SavingsTransactionType::Refund)
            ->value('amount_kobo'))->toBe(100_000_00);
});

it('refunds a rejection only once, however many times an admin resolves it', function () {
    $goal = testPaidPlan($this->customer, $this->product);
    $order = app(SavingsGoalService::class)->fulfil($this->customer, $goal)->first();
    app(OrderService::class)->confirm($this->admin, $order);
    app(PreparationService::class)->reject($this->vendorUser, $order->refresh(), 'Out of stock');
    app(PreparationService::class)->resolveRejectionToSavings($this->admin, $order->refresh());

    // A second attempt is rejected by the status guard, and even if it were
    // not, the refund reference is keyed to the order.
    try {
        app(PreparationService::class)->resolveRejectionToSavings($this->admin, $order->refresh());
    } catch (ValidationException) {
        // expected
    }

    expect(app(SavingsService::class)->creditKobo($this->customer))->toBe(100_000_00)
        ->and(SavingsTransaction::query()
            ->where('user_id', $this->customer->id)
            ->where('type', SavingsTransactionType::Refund)
            ->count())->toBe(1);
});

it('keeps the logistics role out of catalog and pricing management', function () {
    $logistics = User::factory()->staff()->create();
    $logistics->forceFill(['two_factor_confirmed_at' => now()])->save();
    $logistics->assignRole('Logistics Personnel');

    $this->actingAs($logistics)
        ->get('http://'.config('app.admin_domain').'/products')
        ->assertForbidden();
});
