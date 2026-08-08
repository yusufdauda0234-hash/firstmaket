<?php

use App\Models\User;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PreparationService;
use App\Shared\Enums\DeliveryOutcome;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The dispatch desk and the courier's own screen, over HTTP.
 *
 * The boundary that matters most here is who may hand out work: a courier
 * must never be able to assign themselves a parcel.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->admin = User::factory()->create(['user_type' => UserType::Staff, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Administrator');
});

function dispatchUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/dispatch'.$path;
}

function courierUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/deliveries'.$path;
}

/** A packed parcel sitting in the dispatch queue. */
function readyParcel($test, int $units = 1): Shipment
{
    $product = Product::factory()->approved()->create([
        'price_kobo' => 5_000_00, 'stock_quantity' => 20,
    ]);

    test()->actingAs($test->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => $units])
        ->assertRedirect();

    $session = app(CartCheckoutService::class)->startCardCheckout(
        $test->customer,
        app(CartService::class)->lines($test->customer),
        [
            'recipient_name' => 'Yakubu Dauda',
            'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ],
    );
    app(CartCheckoutService::class)->completePaidSession($session);

    $shipment = Shipment::query()->latest('id')->firstOrFail();

    foreach ($shipment->orders()->get() as $order) {
        if ($order->status === OrderStatus::Pending) {
            app(OrderService::class)->confirm($test->admin, $order);
        }
        app(PreparationService::class)->markReadyForPickup($order->vendor->user, $order->fresh());
    }

    return $shipment->fresh();
}

// ── The desk ────────────────────────────────────────────────────────────

it('shows the queue to a dispatcher', function () {
    readyParcel($this);

    $this->actingAs($this->admin)
        ->get(dispatchUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Logistics/Dispatch')
            ->has('waiting', 1)
            ->has('couriers'));
});

it('will not let a courier open the dispatch desk', function () {
    // The boundary that matters: a courier handing themselves work.
    $this->actingAs(makeCourier())->get(dispatchUrl())->assertForbidden();
});

it('will not let a courier assign a parcel', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();

    $this->actingAs($courier)
        ->post(dispatchUrl('/assign'), ['uuids' => [$parcel->uuid], 'courier_id' => $courier->id])
        ->assertForbidden();

    expect($parcel->fresh()->activeAssignment())->toBeNull();
});

it('assigns a batch to one courier', function () {
    $first = readyParcel($this);
    $second = readyParcel($this);
    $courier = makeCourier();

    $this->actingAs($this->admin)
        ->post(dispatchUrl('/assign'), [
            'uuids' => [$first->uuid, $second->uuid],
            'courier_id' => $courier->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($first->fresh()->activeAssignment()->logistics_user_id)->toBe($courier->id)
        ->and($second->fresh()->activeAssignment()->logistics_user_id)->toBe($courier->id);
});

it('carries on when one parcel was taken while the page was open', function () {
    $mine = readyParcel($this);
    $taken = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $taken, makeCourier('Someone Else'));
    app(DeliveryService::class)->advance(
        $taken->fresh()->activeAssignment()->logisticsUser,
        $taken->fresh(),
        ShipmentStatus::Packed,
    );

    $this->actingAs($this->admin)
        ->post(dispatchUrl('/assign'), [
            'uuids' => [$mine->uuid, $taken->uuid],
            'courier_id' => $courier->id,
        ])
        ->assertRedirect();

    // Skipping one is right; failing the batch would punish the other.
    expect($mine->fresh()->activeAssignment()->logistics_user_id)->toBe($courier->id);
});

it('filters the queue by state', function () {
    readyParcel($this);

    $this->actingAs($this->admin)
        ->get(dispatchUrl('?state=Kano'))
        ->assertInertia(fn ($page) => $page->has('waiting', 0));
});

it('separates parcels that are out of retries', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();

    foreach (range(1, Shipment::MAX_ATTEMPTS) as $ignored) {
        app(DeliveryService::class)->assign($this->admin, $parcel->fresh(), $courier);
        $parcel = walkParcel($courier, $parcel->fresh(), ShipmentStatus::OutForDelivery);
        app(DeliveryService::class)->recordFailure($courier, $parcel->fresh(), DeliveryOutcome::NoOneHome);
    }

    // Surfaced, not buried: each one is waiting on a human decision.
    $this->actingAs($this->admin)
        ->get(dispatchUrl())
        ->assertInertia(fn ($page) => $page->has('exceptions', 1)->has('waiting', 0));
});

it('recalls a parcel back into the queue', function () {
    $parcel = readyParcel($this);
    app(DeliveryService::class)->assign($this->admin, $parcel, makeCourier());

    $this->actingAs($this->admin)
        ->post(dispatchUrl('/'.$parcel->uuid.'/recall'))
        ->assertRedirect();

    expect($parcel->fresh()->activeAssignment())->toBeNull()
        ->and(Shipment::query()->awaitingCourier()->count())->toBe(1);
});

it('demands a real reason to close a delivery without the code', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);
    $parcel = walkParcel($courier, $parcel->fresh(), ShipmentStatus::OutForDelivery);

    $this->actingAs($this->admin)
        ->post(dispatchUrl('/'.$parcel->uuid.'/force-deliver'), ['reason' => 'ok'])
        ->assertSessionHasErrors('reason');

    expect($parcel->fresh()->status)->not->toBe(ShipmentStatus::Delivered);
});

// ── The courier's screen ────────────────────────────────────────────────

it('shows a courier only what they are carrying', function () {
    $mine = readyParcel($this);
    readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $mine, $courier);

    $this->actingAs($courier)
        ->get(courierUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Logistics/Tasks')
            ->has('stops', 1)
            ->where('stops.0.uuid', $mine->uuid));
});

it('gives a courier a real dashboard rather than a stub', function () {
    $parcel = readyParcel($this, 2);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);

    $this->actingAs($courier)
        ->get('http://'.strtolower((string) config('app.admin_domain')).'/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Logistics/Dashboard')
            ->where('stats.carrying', 1)
            ->where('stops.0.unitCount', 2));
});

it('lets a courier advance their own parcel', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);

    $this->actingAs($courier)
        ->post(courierUrl('/'.$parcel->uuid.'/advance'))
        ->assertRedirect();

    expect($parcel->fresh()->status)->toBe(ShipmentStatus::Packed);
});

it('refuses a courier acting on somebody else\'s parcel', function () {
    $parcel = readyParcel($this);
    app(DeliveryService::class)->assign($this->admin, $parcel, makeCourier('Theirs'));

    // A forged uuid finds nothing rather than being argued with.
    $this->actingAs(makeCourier('Mine'))
        ->post(courierUrl('/'.$parcel->uuid.'/advance'))
        ->assertForbidden();

    expect($parcel->fresh()->status)->toBe(ShipmentStatus::ReadyForPickup);
});

it('hands over from the courier screen with the code', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);
    $parcel = walkParcel($courier, $parcel->fresh(), ShipmentStatus::OutForDelivery);

    $this->actingAs($courier)
        ->post(courierUrl('/'.$parcel->uuid.'/deliver'), ['delivery_code' => $parcel->delivery_code])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($parcel->fresh()->status)->toBe(ShipmentStatus::Delivered);
});

it('rejects a wrong code from the courier screen', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);
    $parcel = walkParcel($courier, $parcel->fresh(), ShipmentStatus::OutForDelivery);

    $wrong = $parcel->delivery_code === '0000' ? '1111' : '0000';

    $this->actingAs($courier)
        ->post(courierUrl('/'.$parcel->uuid.'/deliver'), ['delivery_code' => $wrong])
        ->assertSessionHasErrors('delivery_code');

    expect($parcel->fresh()->status)->not->toBe(ShipmentStatus::Delivered);
});

it('records a failure from the courier screen', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);
    $parcel = walkParcel($courier, $parcel->fresh(), ShipmentStatus::OutForDelivery);

    $this->actingAs($courier)
        ->post(courierUrl('/'.$parcel->uuid.'/fail'), [
            'outcome' => 'no_one_home',
            'note' => 'Gate locked, nobody answering',
        ])
        ->assertRedirect();

    expect($parcel->fresh()->status)->toBe(ShipmentStatus::Failed)
        ->and($parcel->fresh()->attempt_count)->toBe(1);
});

it('will not accept a made-up failure reason', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);
    $parcel = walkParcel($courier, $parcel->fresh(), ShipmentStatus::OutForDelivery);

    $this->actingAs($courier)
        ->post(courierUrl('/'.$parcel->uuid.'/fail'), ['outcome' => 'delivered'])
        ->assertSessionHasErrors('outcome');
});

it('moves a batch of stops on, each to its own next step', function () {
    $early = readyParcel($this);
    $later = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $early, $courier);
    app(DeliveryService::class)->assign($this->admin, $later, $courier);
    app(DeliveryService::class)->advance($courier, $later->fresh(), ShipmentStatus::Packed);

    $this->actingAs($courier)
        ->post(courierUrl('/bulk-advance'), ['uuids' => [$early->uuid, $later->uuid]])
        ->assertRedirect();

    // A mixed list must not be dragged to one shared status.
    expect($early->fresh()->status)->toBe(ShipmentStatus::Packed)
        ->and($later->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('never closes a delivery from the bulk button', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);
    $parcel = walkParcel($courier, $parcel->fresh(), ShipmentStatus::OutForDelivery);

    $this->actingAs($courier)
        ->post(courierUrl('/bulk-advance'), ['uuids' => [$parcel->uuid]])
        ->assertRedirect();

    // A bulk button that could hand parcels over would make the code
    // pointless.
    expect($parcel->fresh()->status)->toBe(ShipmentStatus::OutForDelivery);
});

it('does not mistake "bulk-advance" for a parcel uuid', function () {
    $this->actingAs(makeCourier())
        ->post(courierUrl('/bulk-advance'), ['uuids' => []])
        ->assertSessionHasErrors('uuids');
});

// ── What the customer sees ──────────────────────────────────────────────

it('shows the customer their delivery code while the parcel is on its way', function () {
    $parcel = readyParcel($this);

    $this->actingAs($this->customer)
        ->get(route('orders.show', $parcel->orders()->first()->uuid))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.deliveryCode', $parcel->delivery_code));
});

it('stops showing the code once the parcel is delivered', function () {
    $parcel = readyParcel($this);
    $courier = makeCourier();
    app(DeliveryService::class)->assign($this->admin, $parcel, $courier);
    $parcel = walkParcel($courier, $parcel->fresh(), ShipmentStatus::Delivered);

    $this->actingAs($this->customer)
        ->get(route('orders.show', $parcel->orders()->first()->uuid))
        ->assertInertia(fn ($page) => $page->where('order.deliveryCode', null));
});
