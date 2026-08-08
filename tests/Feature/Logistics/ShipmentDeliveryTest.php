<?php

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Logistics\Models\DeliveryAttempt;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PreparationService;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\DeliveryOutcome;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Parcels: how goods actually travel.
 *
 * Orders stay one per unit because that is what commission and payout are
 * computed on. A shipment is one box — one pickup, one doorstep — and it is
 * what a courier is given, what fails, and what a code closes.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->admin = User::factory()->create(['user_type' => UserType::Staff, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Administrator');

    $this->delivery = app(DeliveryService::class);
});

/** A paid checkout, so the shipments are built the way production builds them. */
function buyForDelivery(User $customer, array $lines): CheckoutSession
{
    foreach ($lines as [$product, $quantity]) {
        test()->actingAs($customer)
            ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => $quantity])
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
    );

    app(CartCheckoutService::class)->completePaidSession($session);

    return $session->fresh();
}

/**
 * Walk every order in a parcel through the vendor's half of the chain.
 *
 * Skips anything already moved, so a test that packed one unit by hand can
 * still call this to finish the rest.
 */
function vendorPacks(Shipment $shipment, User $admin): Shipment
{
    // Queried, not read off the relation: a caller that already packed one
    // unit by hand holds a cached collection where it still looks Pending.
    foreach ($shipment->orders()->get() as $order) {
        if ($order->status === OrderStatus::Pending) {
            app(OrderService::class)->confirm($admin, $order);
        }

        if ($order->fresh()->status === OrderStatus::Processing) {
            app(PreparationService::class)->markReadyForPickup($order->vendor->user, $order->fresh());
        }
    }

    return $shipment->fresh();
}

// ── One box, not one row per unit ───────────────────────────────────────

it('makes one parcel for several units of the same item', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);

    buyForDelivery($this->customer, [[$product, 3]]);

    // Three orders — commission is per unit — but one box to carry.
    expect(Order::query()->count())->toBe(3)
        ->and(Shipment::query()->count())->toBe(1)
        ->and(Shipment::query()->first()->unitCount())->toBe(3);
});

it('lets an order be attached to a parcel by mass assignment', function () {
    // shipment_id was missing from Order::$fillable, so a plain create()
    // silently dropped it and the order ended up in no parcel at all.
    // ShipmentBuilder uses a query-builder update, which bypasses fillable —
    // so the omission worked by accident and only bit outside that one path.
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = Shipment::query()->firstOrFail();

    // A factory bypasses mass-assignment protection entirely, so it cannot
    // catch this — update() is the path that actually consults $fillable.
    $order = $shipment->orders()->first();
    $order->forceFill(['shipment_id' => null])->save();

    $order->update(['shipment_id' => $shipment->id]);

    expect($order->fresh()->shipment_id)->toBe($shipment->id);
});

it('makes one parcel per vendor, because they are two pickups', function () {
    $a = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    $b = Product::factory()->approved()->create(['price_kobo' => 8_000_00, 'stock_quantity' => 10]);

    buyForDelivery($this->customer, [[$a, 1], [$b, 1]]);

    expect(Shipment::query()->count())->toBe(2);
});

it('does not split a parcel when the webhook is replayed', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    $session = buyForDelivery($this->customer, [[$product, 2]]);

    app(CartCheckoutService::class)->completePaidSession($session->fresh());

    expect(Shipment::query()->count())->toBe(1);
});

// ── Not ready until the vendor is done ──────────────────────────────────

it('keeps a parcel out of the dispatch queue until every unit is packed', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 2]]);

    $shipment = Shipment::query()->firstOrFail();
    expect($shipment->status)->toBe(ShipmentStatus::Pending);

    // One of two packed — a courier sent now collects half a box.
    $first = $shipment->orders->first();
    app(OrderService::class)->confirm($this->admin, $first);
    app(PreparationService::class)->markReadyForPickup($first->vendor->user, $first->fresh());

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Pending)
        ->and(Shipment::query()->awaitingCourier()->count())->toBe(0);

    vendorPacks($shipment, $this->admin);

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::ReadyForPickup)
        ->and(Shipment::query()->awaitingCourier()->count())->toBe(1);
});

it('cancels the parcel when every unit in it is rejected', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 2]]);

    $shipment = Shipment::query()->firstOrFail();

    foreach ($shipment->orders as $order) {
        app(OrderService::class)->confirm($this->admin, $order);
        app(PreparationService::class)->reject($order->vendor->user, $order->fresh(), 'Out of stock');
    }

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Cancelled);
});

// ── Assignment ──────────────────────────────────────────────────────────

it('gives a parcel to a courier', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);

    expect($shipment->fresh()->activeAssignment()->logistics_user_id)->toBe($courier->id)
        ->and(Shipment::query()->awaitingCourier()->count())->toBe(0);
});

it('refuses to assign to somebody who is not a courier', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);

    $this->delivery->assign($this->admin, $shipment, User::factory()->create());
})->throws(ValidationException::class);

it('takes the parcel off the previous courier when it is reassigned', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);

    $first = makeCourier('First Courier');
    $second = makeCourier('Second Courier');

    $this->delivery->assign($this->admin, $shipment, $first);
    $this->delivery->assign($this->admin, $shipment->fresh(), $second);

    // Cancelled, not deleted: who was carrying it and when is the first
    // question asked when a parcel goes missing.
    expect($shipment->fresh()->activeAssignment()->logistics_user_id)->toBe($second->id)
        ->and($shipment->assignments()->count())->toBe(2);
});

// ── Moving it, and the orders with it ───────────────────────────────────

it('moves every order in the parcel when the parcel moves', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 3]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);

    $this->delivery->advance($courier, $shipment->fresh(), ShipmentStatus::Packed);

    // A customer whose tracking page disagreed with the courier's app would
    // be right to distrust both.
    foreach ($shipment->fresh()->orders as $order) {
        expect($order->status)->toBe(OrderStatus::Packed);
    }
});

it('refuses a step out of order', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);

    $this->delivery->advance($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
})->throws(ValidationException::class);

it('refuses a courier who is not carrying it', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $this->delivery->assign($this->admin, $shipment, makeCourier('Theirs'));

    $this->delivery->advance(makeCourier('Mine'), $shipment->fresh(), ShipmentStatus::Packed);
})->throws(ValidationException::class);

// ── Proof of delivery ───────────────────────────────────────────────────

it('will not let advance reach Delivered at all', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    // The whole point of the code: handing over is not just another step.
    $this->delivery->advance($courier, $shipment, ShipmentStatus::Delivered);
})->throws(ValidationException::class);

it('refuses the wrong code', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $wrong = $shipment->delivery_code === '0000' ? '1111' : '0000';

    expect(fn () => $this->delivery->deliver($courier, $shipment, $wrong))
        ->toThrow(ValidationException::class)
        ->and($shipment->fresh()->status)->not->toBe(ShipmentStatus::Delivered);
});

it('hands over against the right code and spends it', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 2]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    $shipment = $shipment->fresh();

    expect($shipment->status)->toBe(ShipmentStatus::Delivered)
        ->and($shipment->delivered_by)->toBe($courier->id)
        ->and($shipment->delivered_at)->not->toBeNull()
        // Spent, or the same code would close a later parcel to the same
        // customer.
        ->and($shipment->delivery_code)->toBeNull()
        ->and($shipment->activeAssignment())->toBeNull();

    foreach ($shipment->orders as $order) {
        expect($order->status)->toBe(OrderStatus::Delivered);
    }
});

it('lets an admin close a delivery without the code, on the record', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->deliverWithoutCode($this->admin, $shipment, 'Customer confirmed by phone');

    // Stamped, so "delivered without proof" is answerable rather than merely
    // indistinguishable.
    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and($shipment->fresh()->proof_overridden_by)->toBe($this->admin->id);
});

it('will not let a courier close a delivery without the code', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->deliverWithoutCode($courier, $shipment, 'I promise I delivered it');
})->throws(ValidationException::class);

// ── Failing ─────────────────────────────────────────────────────────────

it('records a failed attempt and puts the parcel back in the queue', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->recordFailure($courier, $shipment, DeliveryOutcome::NoOneHome, 'Gate locked');

    $shipment = $shipment->fresh();

    expect($shipment->status)->toBe(ShipmentStatus::Failed)
        ->and($shipment->attempt_count)->toBe(1)
        ->and($shipment->isExhausted())->toBeFalse()
        // Back on the board for another run.
        ->and(Shipment::query()->awaitingCourier()->count())->toBe(1)
        ->and(DeliveryAttempt::query()->count())->toBe(1);
});

it('leaves the orders and the money alone when a delivery fails', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->recordFailure($courier, $shipment, DeliveryOutcome::NoOneHome);

    // Nobody was home. The goods are fine and the customer has paid — the
    // order is not cancelled, it goes back out tomorrow.
    foreach ($shipment->fresh()->orders as $order) {
        expect($order->status)->toBe(OrderStatus::OutForDelivery);
    }
});

it('counts the failed run as work the courier did', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->recordFailure($courier, $shipment, DeliveryOutcome::WrongAddress);

    // Failed, not cancelled: cancelled means the assignment was withdrawn.
    expect($shipment->assignments()->first()->status)->toBe(DeliveryAssignmentStatus::Failed);
});

it('stops sending a parcel back out after three failures', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();

    foreach (range(1, Shipment::MAX_ATTEMPTS) as $ignored) {
        $this->delivery->assign($this->admin, $shipment->fresh(), $courier);
        $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
        $this->delivery->recordFailure($courier, $shipment->fresh(), DeliveryOutcome::NoOneHome);
    }

    $shipment = $shipment->fresh();

    // A fourth trip to an address that has refused three times is not a
    // delivery problem — a human has to look at it.
    expect($shipment->attempt_count)->toBe(Shipment::MAX_ATTEMPTS)
        ->and($shipment->isExhausted())->toBeTrue()
        ->and(DeliveryAttempt::query()->count())->toBe(Shipment::MAX_ATTEMPTS);
});

it('still delivers on a later attempt after an early failure', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00, 'stock_quantity' => 10]);
    buyForDelivery($this->customer, [[$product, 1]]);
    $shipment = vendorPacks(Shipment::query()->firstOrFail(), $this->admin);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->recordFailure($courier, $shipment, DeliveryOutcome::NoOneHome);

    // Back out with the same courier the next day.
    $shipment = $shipment->fresh();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $this->delivery->deliver($courier, $shipment->fresh(), (string) $shipment->delivery_code);

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Delivered)
        // Both runs are on the record — two trips to one address is a real
        // cost, and only the per-attempt log can say so.
        ->and(DeliveryAttempt::query()->count())->toBe(2);
});
