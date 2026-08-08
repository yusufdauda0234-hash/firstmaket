<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Logistics\Models\CourierCashMovement;
use App\Modules\Logistics\Models\CourierProfile;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\CourierCashService;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PayOnDeliveryPolicy;
use App\Modules\Orders\Services\PreparationService;
use App\Shared\Enums\DeliveryOutcome;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Cash on delivery.
 *
 * The checkout half is small. The half that matters is that real notes end
 * up in a courier's pocket, so every one of them has a row, a courier cannot
 * clear their own balance, and a delivered parcel can never exist without the
 * cash being accounted for.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    Setting::set('orders.pay_on_delivery_enabled', true);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->admin = User::factory()->create(['user_type' => UserType::Staff, 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('Administrator');

    $this->cash = app(CourierCashService::class);
    $this->delivery = app(DeliveryService::class);
});

/** A pay-on-delivery checkout, cleared through the webhook path. */
function podPurchase(User $customer, int $priceKobo = 20_000_00, int $quantity = 1): Shipment
{
    $product = Product::factory()->approved()->create([
        'price_kobo' => $priceKobo, 'stock_quantity' => 20,
    ]);

    test()->actingAs($customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => $quantity])
        ->assertRedirect();

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
        null,
        true,
    );

    app(CartCheckoutService::class)->completePaidSession($session);

    $shipment = Shipment::query()->latest('id')->firstOrFail();

    foreach ($shipment->orders()->get() as $order) {
        app(OrderService::class)->confirm(test()->admin, $order);
        app(PreparationService::class)->markReadyForPickup($order->vendor->user, $order->fresh());
    }

    return $shipment->fresh();
}

// ── Checkout ────────────────────────────────────────────────────────────

it('charges only the delivery fee upfront', function () {
    podPurchase($this->customer, 20_000_00);

    $session = CheckoutSession::query()->latest('id')->firstOrFail();

    // ₦20,000 goods + ₦1,500 delivery. Paystack is asked for the fee alone;
    // the goods are owed at the door.
    expect($session->total_amount_kobo)->toBe(1_500_00)
        ->and($session->collect_on_delivery_kobo)->toBe(20_000_00)
        ->and($session->payment_method)->toBe('pay_on_delivery');
});

it('leaves the goods unpaid until the door', function () {
    podPurchase($this->customer);

    foreach (Order::query()->get() as $order) {
        expect($order->goods_paid_at)->toBeNull();
    }
});

it('puts what is owed on the parcel', function () {
    $shipment = podPurchase($this->customer, 20_000_00, 2);

    expect($shipment->collect_on_delivery_kobo)->toBe(40_000_00);
});

it('splits what is owed across parcels from different vendors', function () {
    $a = Product::factory()->approved()->create(['price_kobo' => 30_000_00, 'stock_quantity' => 5]);
    $b = Product::factory()->approved()->create(['price_kobo' => 10_000_00, 'stock_quantity' => 5]);

    foreach ([$a, $b] as $product) {
        $this->actingAs($this->customer)
            ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1]);
    }

    $session = app(CartCheckoutService::class)->startCardCheckout(
        $this->customer,
        app(CartService::class)->lines($this->customer),
        [
            'recipient_name' => 'Yakubu Dauda', 'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa',
        ],
        null,
        true,
    );
    app(CartCheckoutService::class)->completePaidSession($session);

    // The parts must sum to exactly what the shopper agreed to hand over.
    expect((int) Shipment::query()->sum('collect_on_delivery_kobo'))->toBe(40_000_00)
        ->and(Shipment::query()->count())->toBe(2);
});

it('charges the whole total when paying by card', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);

    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1]);

    $session = app(CartCheckoutService::class)->startCardCheckout(
        $this->customer,
        app(CartService::class)->lines($this->customer),
        [
            'recipient_name' => 'Yakubu Dauda', 'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa',
        ],
    );

    expect($session->total_amount_kobo)->toBe(21_500_00)
        ->and($session->collect_on_delivery_kobo)->toBe(0);
});

// ── The bounds ──────────────────────────────────────────────────────────

it('is refused when switched off', function () {
    Setting::set('orders.pay_on_delivery_enabled', false);

    expect(PayOnDeliveryPolicy::refusalReason($this->customer, 10_000_00, 'Lagos'))
        ->toContain('not being offered');
});

it('is refused above the ceiling', function () {
    Setting::set('orders.pay_on_delivery_max_kobo', 50_000_00);

    expect(PayOnDeliveryPolicy::isAvailableFor($this->customer, 50_000_00, 'Lagos'))->toBeTrue()
        ->and(PayOnDeliveryPolicy::isAvailableFor($this->customer, 50_000_01, 'Lagos'))->toBeFalse();
});

it('is refused outside the states it is offered in', function () {
    Setting::set('orders.pay_on_delivery_states', ['Lagos']);

    expect(PayOnDeliveryPolicy::isAvailableFor($this->customer, 10_000_00, 'Lagos'))->toBeTrue()
        ->and(PayOnDeliveryPolicy::refusalReason($this->customer, 10_000_00, 'Kano'))
        ->toContain('Kano');
});

it('is offered everywhere when no states are named', function () {
    Setting::set('orders.pay_on_delivery_states', []);

    expect(PayOnDeliveryPolicy::isAvailableFor($this->customer, 10_000_00, 'Kano'))->toBeTrue();
});

it('cannot be posted past the ceiling with a stale tab', function () {
    Setting::set('orders.pay_on_delivery_max_kobo', 5_000_00);

    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1]);

    // The screen would not offer it, so the only way here is a stale page or
    // a hand-rolled request. Both are refused.
    $this->actingAs($this->customer)
        ->post(route('cart.checkout.store'), [
            'recipient_name' => 'Yakubu Dauda', 'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa',
            'payment_method' => 'pay_on_delivery',
        ])
        ->assertSessionHasErrors('payment_method');

    expect(Order::query()->count())->toBe(0);
});

// ── The cash ledger ─────────────────────────────────────────────────────

it('records the cash when the parcel is handed over', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    $movement = CourierCashMovement::query()->collections()->firstOrFail();

    expect($movement->amount_kobo)->toBe(20_000_00)
        ->and($movement->courier_user_id)->toBe($courier->id)
        ->and($this->cash->balanceKobo($courier))->toBe(20_000_00);
});

it('marks the goods paid at the door', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    foreach (Order::query()->get() as $order) {
        expect($order->goods_paid_at)->not->toBeNull();
    }
});

it('never banks the same parcel twice', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $code = (string) $shipment->delivery_code;

    $this->delivery->deliver($courier, $shipment, $code);
    // Replayed. The parcel is already delivered, so it returns early.
    $this->delivery->deliver($courier, $shipment->fresh(), $code);

    expect(CourierCashMovement::query()->collections()->count())->toBe(1)
        ->and($this->cash->balanceKobo($courier))->toBe(20_000_00);
});

it('takes no cash on a prepaid parcel', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 20_000_00, 'stock_quantity' => 5]);
    $this->actingAs($this->customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1]);

    $session = app(CartCheckoutService::class)->startCardCheckout(
        $this->customer,
        app(CartService::class)->lines($this->customer),
        [
            'recipient_name' => 'Yakubu Dauda', 'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa',
        ],
    );
    app(CartCheckoutService::class)->completePaidSession($session);

    $shipment = Shipment::query()->latest('id')->firstOrFail();
    foreach ($shipment->orders()->get() as $order) {
        app(OrderService::class)->confirm($this->admin, $order);
        app(PreparationService::class)->markReadyForPickup($order->vendor->user, $order->fresh());
    }

    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment->fresh(), $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    expect(CourierCashMovement::query()->count())->toBe(0)
        ->and($this->cash->balanceKobo($courier))->toBe(0);
});

// ── Handing it in ───────────────────────────────────────────────────────

it('does not reduce the balance until the office confirms', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    $movement = $this->cash->declareRemittance($courier, 20_000_00);

    // A courier saying they paid it in is not the office having it.
    expect($this->cash->balanceKobo($courier))->toBe(20_000_00)
        ->and($this->cash->pendingRemittanceKobo($courier))->toBe(20_000_00);

    $this->cash->confirmRemittance($this->admin, $movement);

    expect($this->cash->balanceKobo($courier))->toBe(0)
        ->and($this->cash->pendingRemittanceKobo($courier))->toBe(0);
});

it('will not let a courier confirm their own hand-in', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    $movement = $this->cash->declareRemittance($courier, 20_000_00);

    // Somebody who can both declare and confirm can clear their balance
    // without producing any cash. That is the whole risk here.
    expect(fn () => $this->cash->confirmRemittance($courier, $movement))
        ->toThrow(ValidationException::class)
        ->and($this->cash->balanceKobo($courier))->toBe(20_000_00);
});

it('will not let a courier hand in more than they hold', function () {
    $courier = makeCourier();

    $this->cash->declareRemittance($courier, 5_000_00);
})->throws(ValidationException::class);

it('will not let a courier declare the same money twice', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    $this->cash->declareRemittance($courier, 20_000_00);

    // The first declaration is still pending, so there is nothing left to
    // declare — otherwise a courier could paper over a shortfall.
    expect(fn () => $this->cash->declareRemittance($courier, 20_000_00))
        ->toThrow(ValidationException::class);
});

// ── The float ceiling ───────────────────────────────────────────────────

it('stops a courier being given more cash than their ceiling', function () {
    $courier = makeCourier();
    CourierProfile::query()->where('user_id', $courier->id)->update(['max_float_kobo' => 25_000_00]);

    expect($this->cash->canCarryMore($courier, 20_000_00))->toBeTrue();

    $shipment = podPurchase($this->customer, 20_000_00);
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    // ₦20,000 already on them, so another ₦20,000 would breach ₦25,000.
    expect($this->cash->canCarryMore($courier, 20_000_00))->toBeFalse()
        ->and($this->cash->canCarryMore($courier, 5_000_00))->toBeTrue();
});

it('treats a zero ceiling as no ceiling', function () {
    $courier = makeCourier();

    expect($this->cash->canCarryMore($courier, 900_000_00))->toBeTrue();
});

// ── Refusals ────────────────────────────────────────────────────────────

it('counts a refusal at the door against the customer', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->recordFailure($courier, $shipment, DeliveryOutcome::Refused, 'Changed their mind');

    expect(PayOnDeliveryPolicy::refusalCount($this->customer))->toBe(1);
});

it('takes the option away after too many refusals', function () {
    Setting::set('orders.pay_on_delivery_max_refusals', 2);
    $courier = makeCourier();

    foreach (range(1, 2) as $ignored) {
        $shipment = podPurchase($this->customer, 20_000_00);
        $this->delivery->assign($this->admin, $shipment, $courier);
        $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
        $this->delivery->recordFailure($courier, $shipment, DeliveryOutcome::Refused);
    }

    // Each refusal cost a wasted round trip. They can still buy, just not
    // this way, and the message says so.
    expect(PayOnDeliveryPolicy::refusalReason($this->customer, 10_000_00, 'Lagos'))
        ->toContain('no longer available on this account');
});

it('does not count nobody being home as a refusal', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->recordFailure($courier, $shipment, DeliveryOutcome::NoOneHome);

    // A locked gate is not the same as turning the courier away.
    expect(PayOnDeliveryPolicy::refusalCount($this->customer))->toBe(0);
});

// ── Vendors are paid on the same terms ──────────────────────────────────

it('leaves the vendor earning unchanged by how the customer paid', function () {
    $shipment = podPurchase($this->customer, 20_000_00);

    foreach ($shipment->orders()->get() as $order) {
        expect($order->vendor_earning_amount_kobo)
            ->toBe($order->locked_price_kobo - $order->commission_amount_kobo);
    }
});

// ── Over HTTP ───────────────────────────────────────────────────────────

it('lets a courier declare a hand-in from their own screen', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    $this->actingAs($courier)
        ->post('http://'.strtolower((string) config('app.admin_domain')).'/deliveries/remit', [
            'amount_naira' => 20000,
        ])
        ->assertRedirect();

    expect($this->cash->pendingRemittanceKobo($courier))->toBe(20_000_00);
});

it('shows the office who is holding what', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code);

    $this->actingAs($this->admin)
        ->get('http://'.strtolower((string) config('app.admin_domain')).'/cash')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Logistics/Cash')
            ->has('outstanding', 1)
            ->where('outstanding.0.balanceKobo', 20_000_00)
            ->has('settings'));
});

it('keeps a courier off the cash screen', function () {
    $this->actingAs(makeCourier())
        ->get('http://'.strtolower((string) config('app.admin_domain')).'/cash')
        ->assertForbidden();
});

it('lets an admin change the bounds', function () {
    $this->actingAs($this->admin)
        ->post('http://'.strtolower((string) config('app.admin_domain')).'/cash/settings', [
            'enabled' => true,
            'max_order_naira' => 30000,
            'states' => ['Lagos', 'Kano'],
            'max_refusals' => 2,
        ])
        ->assertRedirect();

    expect(PayOnDeliveryPolicy::maxOrderKobo())->toBe(30_000_00)
        ->and(PayOnDeliveryPolicy::states())->toBe(['Lagos', 'Kano'])
        ->and(PayOnDeliveryPolicy::maxRefusals())->toBe(2);
});

// ── Online settlement must not be collected a second time ────────────────

it('does not take cash for goods the customer already paid for online', function () {
    /*
     * The failure this guards: a shopper pays the goods balance online after
     * delivery is arranged, then also hands the courier cash at the door.
     * The cash branch used to overwrite the online settlement with
     * `cash`, which then credited the courier's balance for money FirstMaket
     * had already received — the shopper charged twice, and only the cash leg
     * left in the record.
     */
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    // Settled online before handover.
    $shipment->forceFill([
        'goods_collection_method' => 'customer_online',
        'goods_paid_at' => now(),
        'goods_paid_by' => $this->customer->id,
    ])->save();

    $this->delivery->deliver($courier, $shipment->fresh(), (string) $shipment->delivery_code, 'cash');

    expect(CourierCashMovement::query()->collections()->count())->toBe(0)
        ->and($this->cash->balanceKobo($courier))->toBe(0)
        ->and($shipment->fresh()->goods_collection_method)->toBe('customer_online');
});

it('still takes cash when nothing has been paid online', function () {
    // The guard must not stop ordinary cash on delivery from working.
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);
    $this->delivery->deliver($courier, $shipment, (string) $shipment->delivery_code, 'cash');

    expect($this->cash->balanceKobo($courier))->toBe(20_000_00)
        ->and($shipment->fresh()->goods_collection_method)->toBe('cash');
});

it('refuses a courier-online handover until the payment is verified', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    expect(fn () => $this->delivery->deliver(
        $courier,
        $shipment,
        (string) $shipment->delivery_code,
        'courier_online',
    ))->toThrow(ValidationException::class);

    expect($shipment->fresh()->status)->not->toBe(ShipmentStatus::Delivered);
});

// ── An admin override still has to say who holds the money ──────────────

it('does not put money on a courier who already saw it paid online', function () {
    /*
     * Closing a pay-on-delivery parcel makes somebody responsible for the
     * balance. The override used to fall through to cash without saying so,
     * which put an online-settled balance on the courier's ledger as a side
     * effect of an admin action they took no part in.
     */
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $shipment->forceFill([
        'goods_collection_method' => 'customer_online',
        'goods_paid_at' => now(),
        'goods_paid_by' => $this->customer->id,
    ])->save();

    $this->delivery->deliverWithoutCode($this->admin, $shipment->fresh(), 'Customer confirmed by phone');

    expect($this->cash->balanceKobo($courier))->toBe(0)
        ->and($shipment->fresh()->goods_collection_method)->toBe('customer_online');
});

it('still treats an override as cash when nothing says otherwise', function () {
    // The default has to keep working: an override usually does mean the
    // courier took the notes.
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->deliverWithoutCode($this->admin, $shipment, 'Customer confirmed by phone');

    expect($this->cash->balanceKobo($courier))->toBe(20_000_00)
        ->and($shipment->fresh()->goods_collection_method)->toBe('cash');
});

it('lets an override leave the balance for the customer to pay online', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->deliverWithoutCode(
        $this->admin,
        $shipment,
        'Customer confirmed by phone',
        'customer_online',
    );

    // No cash on anybody's ledger, and the balance is still owed.
    expect($this->cash->balanceKobo($courier))->toBe(0)
        ->and($shipment->fresh()->goods_paid_at)->toBeNull()
        ->and($shipment->fresh()->goods_collection_method)->toBe('customer_online');
});

it('records on the audit trail how an override settled the goods', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();

    $this->delivery->assign($this->admin, $shipment, $courier);
    $shipment = walkParcel($courier, $shipment->fresh(), ShipmentStatus::OutForDelivery);

    $this->delivery->deliverWithoutCode($this->admin, $shipment, 'Confirmed by phone', 'customer_online');

    $entry = AuditLog::query()
        ->where('action', 'logistics.delivered_without_code')
        ->latest('id')
        ->firstOrFail();

    expect($entry->new_values['collection_method'] ?? null)->toBe('customer_online');
});
