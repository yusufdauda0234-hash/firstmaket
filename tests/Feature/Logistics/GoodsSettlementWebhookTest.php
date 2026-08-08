<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Logistics\Models\CourierCashMovement;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Payments\Models\PaystackWebhookEvent;
use App\Shared\Enums\PaystackTransactionStatus;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Settling a goods balance through the Paystack webhook.
 *
 * This is the only place a customer-online payment is ever recognised, so it
 * carries the whole risk of the flow: money has already left the shopper by
 * the time it runs. It has to be exact about the amount, idempotent against
 * Paystack's retries, and it must never answer with a 500 — Paystack replies
 * to those by sending the same event again, forever.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

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

/** A shipment delivered with its goods balance still owing, plus its payment. */
function owingShipmentAndPayment(User $customer, int $goodsKobo = 20_000_00): array
{
    $shipment = podPurchase($customer, $goodsKobo);
    $shipment->forceFill([
        'status' => ShipmentStatus::Delivered,
        'delivered_at' => now(),
        'goods_collection_method' => 'customer_online',
    ])->save();

    $reference = 'FMG_'.Str::lower((string) Str::ulid());

    $transaction = PaystackTransaction::query()->create([
        'user_id' => $customer->id,
        'paystack_reference' => $reference,
        'amount_kobo' => $shipment->collect_on_delivery_kobo,
        'currency' => 'NGN',
        'status' => PaystackTransactionStatus::Pending,
        'purpose' => 'shipment_goods',
        'shipment_id' => $shipment->id,
    ]);

    return [$shipment->fresh(), $transaction, $reference];
}

it('settles the balance when the exact amount arrives', function () {
    [$shipment, $transaction, $reference] = owingShipmentAndPayment($this->customer);

    postWebhook(chargeSuccessPayload($reference, $shipment->collect_on_delivery_kobo))
        ->assertOk();

    $shipment->refresh();

    expect($shipment->goods_paid_at)->not->toBeNull()
        ->and($shipment->goods_paid_by)->toBe($this->customer->id)
        ->and($transaction->fresh()->status)->toBe(PaystackTransactionStatus::Success);
});

it('marks the orders paid once the money is verified', function () {
    [$shipment, , $reference] = owingShipmentAndPayment($this->customer);

    postWebhook(chargeSuccessPayload($reference, $shipment->collect_on_delivery_kobo));

    expect($shipment->orders()->whereNull('goods_paid_at')->count())->toBe(0);
});

it('refuses to settle on a short payment', function () {
    // Underpaying must not clear the balance for the full amount.
    [$shipment, $transaction, $reference] = owingShipmentAndPayment($this->customer, 20_000_00);

    postWebhook(chargeSuccessPayload($reference, 5_000_00))->assertOk();

    expect($shipment->fresh()->goods_paid_at)->toBeNull()
        ->and($transaction->fresh()->status)->not->toBe(PaystackTransactionStatus::Success);
});

it('records the refusal rather than throwing at Paystack', function () {
    /*
     * A 500 makes Paystack retry the same event indefinitely while the money
     * has already moved. The event is recorded as failed instead, which
     * leaves the same evidence without the retry storm.
     */
    [, , $reference] = owingShipmentAndPayment($this->customer, 20_000_00);

    postWebhook(chargeSuccessPayload($reference, 5_000_00))->assertOk();

    $event = PaystackWebhookEvent::query()->latest('id')->first();

    expect($event->processing_status)->toBe('failed')
        ->and($event->error_message)->not->toBeNull();
});

it('does not settle twice when Paystack retries', function () {
    [$shipment, , $reference] = owingShipmentAndPayment($this->customer);

    postWebhook(chargeSuccessPayload($reference, $shipment->collect_on_delivery_kobo));
    $firstPaidAt = $shipment->fresh()->goods_paid_at;

    postWebhook(chargeSuccessPayload($reference, $shipment->collect_on_delivery_kobo));

    expect($shipment->fresh()->goods_paid_at?->timestamp)->toBe($firstPaidAt?->timestamp);
});

it('never credits a courier cash balance for an online settlement', function () {
    // The whole point of paying online is that no notes change hands.
    [$shipment, , $reference] = owingShipmentAndPayment($this->customer);

    postWebhook(chargeSuccessPayload($reference, $shipment->collect_on_delivery_kobo));

    expect(CourierCashMovement::query()->collections()->count())->toBe(0);
});

it('refuses a goods payment that points at no shipment', function () {
    $reference = 'FMG_'.Str::lower((string) Str::ulid());

    PaystackTransaction::query()->create([
        'user_id' => $this->customer->id,
        'paystack_reference' => $reference,
        'amount_kobo' => 20_000_00,
        'currency' => 'NGN',
        'status' => PaystackTransactionStatus::Pending,
        'purpose' => 'shipment_goods',
        'shipment_id' => null,
    ]);

    // Must be answered, not thrown on — see the retry note above.
    postWebhook(chargeSuccessPayload($reference, 20_000_00))->assertOk();

    expect(PaystackWebhookEvent::query()->latest('id')->first()->processing_status)->toBe('failed');
});
