<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Shared\Enums\PaystackTransactionStatus;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakePaymentGateway;

/**
 * Who is allowed to start a payment for a goods balance, and when.
 *
 * These two endpoints move money, and neither had any test. Authorisation is
 * the whole point: the amount is taken from the shipment rather than the
 * request, so the only thing standing between a shopper and paying somebody
 * else's balance — or seeing somebody else's order total — is the ownership
 * check in the controller.
 */
uses()->group('goods-payment');

/*
 * Its own setup: beforeEach is per-file, so the fixtures PayOnDeliveryTest
 * builds are not visible here. podPurchase() is a global Pest helper and is,
 * because every test file is loaded before any of them run.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    /*
     * Both endpoints under test end in a hand-off to Paystack's hosted
     * checkout. Without this the suite makes a real HTTPS call to
     * api.paystack.co and fails on a network timeout — a red build that says
     * nothing about the code, and a test that cannot run offline.
     */
    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $this->gateway);

    Setting::set('orders.pay_on_delivery_enabled', true);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->admin = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('Administrator');

    $this->delivery = app(DeliveryService::class);
});

function paidShipmentFixture(User $customer, int $goodsKobo = 20_000_00): Shipment
{
    $shipment = podPurchase($customer, $goodsKobo);

    $shipment->forceFill(['status' => ShipmentStatus::Delivered, 'delivered_at' => now()])->save();

    return $shipment->fresh();
}

function orderFor(Shipment $shipment): Order
{
    return $shipment->orders()->firstOrFail();
}

// ── The customer's "Pay now" ────────────────────────────────────────────

it('refuses to let a shopper pay somebody else\'s order', function () {
    $shipment = paidShipmentFixture($this->customer);

    $stranger = User::factory()->create(['phone_verified_at' => now()]);
    $stranger->assignRole('Customer');

    $this->actingAs($stranger)
        ->post('/orders/'.orderFor($shipment)->uuid.'/pay-goods')
        ->assertForbidden();

    expect(PaystackTransaction::query()->where('purpose', 'shipment_goods')->count())->toBe(0);
});

it('refuses payment before the parcel has been delivered', function () {
    // Paying for goods still on the van removes the leverage that makes pay
    // on delivery worth offering.
    $shipment = podPurchase($this->customer, 20_000_00);

    $this->actingAs($this->customer)
        ->post('/orders/'.orderFor($shipment)->uuid.'/pay-goods')
        ->assertStatus(422);
});

it('refuses a second payment once the balance is settled', function () {
    $shipment = paidShipmentFixture($this->customer);
    $shipment->forceFill(['goods_paid_at' => now(), 'goods_collection_method' => 'cash'])->save();

    $this->actingAs($this->customer)
        ->post('/orders/'.orderFor($shipment)->uuid.'/pay-goods')
        ->assertStatus(422);
});

it('requires signing in', function () {
    $shipment = paidShipmentFixture($this->customer);

    $this->post('/orders/'.orderFor($shipment)->uuid.'/pay-goods')
        ->assertRedirect();
});

// ── The courier's "pay online now" ──────────────────────────────────────

function courierPayUrl(Shipment $shipment): string
{
    return 'http://'.strtolower((string) config('app.admin_domain'))
        .'/deliveries/'.$shipment->uuid.'/pay-goods';
}

it('refuses a courier who is not carrying the parcel', function () {
    $shipment = paidShipmentFixture($this->customer);
    $other = makeCourier();

    $this->actingAs($other)
        ->post(courierPayUrl($shipment))
        ->assertForbidden();

    expect(PaystackTransaction::query()->where('purpose', 'shipment_goods')->count())->toBe(0);
});

it('refuses a courier payment once the balance is settled', function () {
    $shipment = podPurchase($this->customer, 20_000_00);
    $courier = makeCourier();
    $this->delivery->assign($this->admin, $shipment, $courier);

    $shipment->fresh()->forceFill(['goods_paid_at' => now()])->save();

    $this->actingAs($courier)
        ->post(courierPayUrl($shipment))
        ->assertStatus(422);
});

// ── The amount is never taken from the request ──────────────────────────

it('charges the shipment balance, not anything the caller sends', function () {
    $shipment = paidShipmentFixture($this->customer, 20_000_00);

    $this->actingAs($this->customer)
        ->post('/orders/'.orderFor($shipment)->uuid.'/pay-goods', [
            'amount_kobo' => 1,
            'amount' => 1,
        ]);

    $transaction = PaystackTransaction::query()->where('purpose', 'shipment_goods')->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->amount_kobo)->toBe(20_000_00)
        ->and($transaction->shipment_id)->toBe($shipment->id)
        ->and($transaction->user_id)->toBe($this->customer->id)
        ->and($transaction->status)->toBe(PaystackTransactionStatus::Pending);
});
