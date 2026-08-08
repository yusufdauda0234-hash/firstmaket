<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PreparationService;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->admin = fulfilmentStaff();
    $this->customer = User::factory()->create();
    $this->product = Product::factory()->approved()->create(['price_kobo' => 100_000_00]);
    $this->product->vendor->user->assignRole('Vendor');
});

function fulfilmentStaff(string $role = 'Administrator'): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function fulfilmentUrl(string $path): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/'.ltrim($path, '/');
}

/** A paid order sitting in the confirmation queue. */
function pendingOrder($test): Order
{
    return testOrder($test->customer, $test->product);
}

/** A parcel already assigned to $courier and sitting at $status. */
function assignedParcel($test, User $courier, ShipmentStatus $status): Shipment
{
    $order = pendingOrder($test);

    app(OrderService::class)->confirm($test->admin, $order);
    app(PreparationService::class)->markReadyForPickup($test->product->vendor->user, $order->refresh());

    $shipment = $order->refresh()->shipment;
    app(DeliveryService::class)->assign($test->admin, $shipment, $courier);

    return walkParcel($courier, $shipment->fresh(), $status);
}

// ── Orders ────────────────────────────────────────────────────────────────

it('confirms several paid orders at once', function () {
    $orders = collect([pendingOrder($this), pendingOrder($this)]);

    $this->actingAs($this->admin)
        ->post(fulfilmentUrl('orders/bulk-confirm'), ['uuids' => $orders->pluck('uuid')->all()])
        ->assertRedirect()
        ->assertSessionHas('success');

    foreach ($orders as $order) {
        expect($order->fresh()->status)->not->toBe(OrderStatus::Pending);
    }
});

it('carries on when one order has already been confirmed', function () {
    $first = pendingOrder($this);
    $second = pendingOrder($this);

    // A colleague got to this one a moment ago.
    app(OrderService::class)->confirm($this->admin, $second);

    $this->actingAs($this->admin)
        ->post(fulfilmentUrl('orders/bulk-confirm'), ['uuids' => [$first->uuid, $second->uuid]])
        ->assertRedirect();

    expect($first->fresh()->status)->not->toBe(OrderStatus::Pending);
});

it('requires at least one order', function () {
    $this->actingAs($this->admin)
        ->post(fulfilmentUrl('orders/bulk-confirm'), ['uuids' => []])
        ->assertSessionHasErrors('uuids');
});

it('caps an order batch at 100', function () {
    $uuids = collect(range(1, 101))->map(fn () => (string) Str::uuid())->all();

    $this->actingAs($this->admin)
        ->post(fulfilmentUrl('orders/bulk-confirm'), ['uuids' => $uuids])
        ->assertSessionHasErrors('uuids');
});

it('blocks bulk confirm without orders.manage', function () {
    $order = pendingOrder($this);

    $this->actingAs(fulfilmentStaff('Support Agent'))
        ->post(fulfilmentUrl('orders/bulk-confirm'), ['uuids' => [$order->uuid]])
        ->assertForbidden();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('does not mistake "bulk-confirm" for an order uuid', function () {
    // The bulk route is registered before orders/{order:uuid}/confirm; if the
    // order were wrong this would 404 looking for an order called that.
    $this->actingAs($this->admin)
        ->post(fulfilmentUrl('orders/bulk-confirm'), ['uuids' => []])
        ->assertSessionHasErrors('uuids');
});

// ── Deliveries ────────────────────────────────────────────────────────────

it('advances each delivery to its own next step', function () {
    $courier = fulfilmentStaff('Logistics Personnel');

    // A courier's list is mixed, so forcing one shared status would drag some
    // orders backwards.
    $early = assignedParcel($this, $courier, ShipmentStatus::ReadyForPickup);
    $later = assignedParcel($this, $courier, ShipmentStatus::Shipped);

    $earlyBefore = $early->status;
    $laterBefore = $later->status;

    $this->actingAs($courier)
        ->post(fulfilmentUrl('deliveries/bulk-advance'), ['uuids' => [$early->uuid, $later->uuid]])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($early->fresh()->status)->not->toBe($earlyBefore)
        ->and($later->fresh()->status)->not->toBe($laterBefore);
});

it('will not advance a delivery assigned to somebody else', function () {
    $mine = fulfilmentStaff('Logistics Personnel');
    $theirs = fulfilmentStaff('Logistics Personnel');

    $order = assignedParcel($this, $theirs, ShipmentStatus::ReadyForPickup);
    $before = $order->status;

    $this->actingAs($mine)
        ->post(fulfilmentUrl('deliveries/bulk-advance'), ['uuids' => [$order->uuid]])
        ->assertRedirect();

    // Scoped to the courier's own assignments, so a forged uuid changes nothing.
    expect($order->fresh()->status)->toBe($before);
});

it('requires at least one delivery', function () {
    $this->actingAs(fulfilmentStaff('Logistics Personnel'))
        ->post(fulfilmentUrl('deliveries/bulk-advance'), ['uuids' => []])
        ->assertSessionHasErrors('uuids');
});

it('blocks bulk advance without delivery.update', function () {
    $courier = fulfilmentStaff('Logistics Personnel');
    $order = assignedParcel($this, $courier, ShipmentStatus::ReadyForPickup);
    $before = $order->status;

    $this->actingAs(fulfilmentStaff('Support Agent'))
        ->post(fulfilmentUrl('deliveries/bulk-advance'), ['uuids' => [$order->uuid]])
        ->assertForbidden();

    expect($order->fresh()->status)->toBe($before);
});
