<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Returns\Models\Refund;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Vendor\Models\VendorEarning;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Services\EarningsService;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PaystackTransactionStatus;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\ReturnReason;
use App\Shared\Enums\ReturnStatus;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakePaymentGateway;

/**
 * Phase 2E: the money half.
 *
 * This is the only outward money path in the system, so these tests are about
 * the four guarantees that make it safe: admin-only, capped, exactly once, and
 * never cash out of a Pay Small Small plan — plus unwinding everything the
 * delivered sale set in motion.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $this->gateway);

    $this->customer = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->returns = app(ReturnService::class);
});

function refundableOrder(User $customer, array $overrides = []): Order
{
    $category = Category::factory()->create();
    $vendor = VendorProfile::factory()->create();
    $product = Product::factory()->approved()->create([
        'category_id' => $category->id,
        'vendor_id' => $vendor->id,
    ]);

    // No factory for this one, and the refund path needs a real session to
    // find the original charge through.
    $session = \App\Modules\Cart\Models\CheckoutSession::query()->create([
        'user_id' => $customer->id,
        'total_amount_kobo' => 500_000,
        'shipping_fee_kobo' => 0,
        'payment_method' => 'card',
        'status' => 'paid',
        'items_snapshot' => [],
        'delivery_address' => '1 Test Street',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'recipient_name' => 'Test Customer',
        'recipient_phone' => '08031234567',
    ]);

    $order = Order::query()->create(array_merge([
        'customer_id' => $customer->id,
        'vendor_id' => $vendor->id,
        'product_id' => $product->id,
        'checkout_session_id' => $session->id,
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

    // The charge that paid for it — what a card refund reverses against.
    PaystackTransaction::query()->create([
        'user_id' => $customer->id,
        'purpose' => 'order',
        'checkout_session_id' => $session->id,
        'paystack_reference' => 'FMC_'.$order->id.'_original',
        'amount_kobo' => 500_000,
        'currency' => 'NGN',
        'status' => PaystackTransactionStatus::Success,
        'webhook_verified_at' => now(),
    ]);

    return $order;
}

/** Walk a return to the point where it can be settled. */
function receivedReturn(User $customer, User $admin, Order $order, ReturnReason $reason = ReturnReason::Damaged)
{
    $returns = app(ReturnService::class);
    $request = $returns->open($customer, $order, $reason);
    $returns->approve($admin, $request);
    $returns->markInTransit($customer, $request);
    $returns->markReceived($admin, $request);

    return $request->refresh();
}

it('refunds to the card, against the original charge, and closes the case', function () {
    $order = refundableOrder($this->customer);
    $request = receivedReturn($this->customer, $this->admin, $order);

    $this->returns->refund($this->admin, $request);

    expect($request->refresh()->status)->toBe(ReturnStatus::Refunded);

    $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();

    expect($refund->destination)->toBe(Refund::DESTINATION_CARD)
        ->and($refund->amount_kobo)->toBe(500_000)
        ->and($refund->status)->toBe(Refund::STATUS_COMPLETED)
        ->and($refund->issued_by)->toBe($this->admin->id);

    // Reversed against the transaction that brought the money in — this is
    // what makes it a reversal and not a payout.
    expect($this->gateway->refunds)->toHaveCount(1)
        ->and($this->gateway->refunds[0]['reference'])->toBe('FMC_'.$order->id.'_original')
        ->and($this->gateway->refunds[0]['amountKobo'])->toBe(500_000);
});

it('pays exactly once however many times the refund is attempted', function () {
    $order = refundableOrder($this->customer);
    $request = receivedReturn($this->customer, $this->admin, $order);

    $this->returns->refund($this->admin, $request);

    // A double-clicked button, a retried job, a second admin.
    expect(fn () => $this->returns->refund($this->admin, $request->refresh()))
        ->toThrow(ValidationException::class);

    expect($this->gateway->refunds)->toHaveCount(1)
        ->and(Refund::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('never refunds more than the order was worth', function () {
    $order = refundableOrder($this->customer, ['promo_discount_kobo' => 200_000]);
    $request = receivedReturn($this->customer, $this->admin, $order);

    $this->returns->refund($this->admin, $request);

    // 500,000 list less a 200,000 code — the customer gets back what they paid.
    expect($this->gateway->refunds[0]['amountKobo'])->toBe(300_000);
});

it('returns a plan order as plan credit and never as cash', function () {
    $plan = SavingsGoal::query()->create([
        'user_id' => $this->customer->id,
        'target_kobo' => 500_000,
        'delivery_fee_kobo' => 0,
        'cadence' => PlanCadence::Monthly,
        'installments' => 5,
        'payments_made' => 5,
        'installment_kobo' => 100_000,
        'paid_kobo' => 500_000,
        'status' => SavingsGoalStatus::Fulfilled,
    ]);

    $order = refundableOrder($this->customer, ['savings_goal_id' => $plan->id]);
    $request = receivedReturn($this->customer, $this->admin, $order);

    $this->returns->refund($this->admin, $request);

    $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();

    expect($refund->destination)->toBe(Refund::DESTINATION_PLAN_CREDIT)
        ->and($refund->status)->toBe(Refund::STATUS_COMPLETED)
        // The card is never touched: money paid into a plan has never been
        // withdrawable, and a return is not a way to make it so.
        ->and($this->gateway->refunds)->toBeEmpty();
});

it('takes the vendor earning back when the sale is reversed', function () {
    $order = refundableOrder($this->customer, ['earnings_credited_at' => now()]);

    // The vendor was paid when delivery was confirmed.
    app(EarningsService::class)->creditOrderEarning(
        vendorId: $order->vendor_id,
        orderId: $order->id,
        amountKobo: 450_000,
    );

    $request = receivedReturn($this->customer, $this->admin, $order);
    $this->returns->refund($this->admin, $request);

    $rows = VendorEarning::query()->where('order_id', $order->id)->get();

    // Two rows, not an edited one: the ledger is a history, so the sale and
    // the return are both visible.
    expect($rows)->toHaveCount(2)
        ->and($rows->sum('amount_kobo'))->toBe(0);
});

it('leaves the vendor alone when they were never credited', function () {
    $order = refundableOrder($this->customer, ['earnings_credited_at' => null]);
    $request = receivedReturn($this->customer, $this->admin, $order);

    $this->returns->refund($this->admin, $request);

    expect(VendorEarning::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('does not mark the return refunded when the provider rejects the refund', function () {
    $order = refundableOrder($this->customer);
    $request = receivedReturn($this->customer, $this->admin, $order);

    $this->gateway->declineRefunds = true;

    expect(fn () => $this->returns->refund($this->admin, $request))
        ->toThrow(ValidationException::class);

    // The whole thing rolls back: a case must never read "refunded" with no
    // money sent.
    expect($request->refresh()->status)->not->toBe(ReturnStatus::Refunded)
        ->and(Refund::query()->where('status', Refund::STATUS_COMPLETED)->count())->toBe(0);
});

it('refuses to refund a case that has not been received or disputed', function () {
    $order = refundableOrder($this->customer);
    $request = $this->returns->open($this->customer, $order, ReturnReason::Damaged);

    // Still only requested — nothing has come back yet.
    expect(fn () => $this->returns->refund($this->admin, $request))
        ->toThrow(ValidationException::class);

    expect($this->gateway->refunds)->toBeEmpty();
});
