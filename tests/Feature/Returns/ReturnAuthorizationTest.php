<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Returns\Models\Refund;
use App\Modules\Returns\Services\ReturnService;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\ReturnReason;
use App\Shared\Enums\UserType;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PaystackTransactionStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\FakePaymentGateway;

/**
 * Who is allowed to do what to a return.
 *
 * The claim being tested is the one that matters most: paying money back out
 * of the business is held behind its own permission, so running the returns
 * desk does not come with the ability to issue refunds.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $this->gateway);

    $this->customer = User::factory()->create();
    $this->returns = app(ReturnService::class);
});

function staffWith(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $user->assignRole($role);

    return $user;
}

function authorizationRefundableOrder(User $customer): Order
{
    $category = Category::factory()->create();
    $vendor = \App\Modules\Vendor\Models\VendorProfile::factory()->create();
    $product = Product::factory()->approved()->create(['category_id' => $category->id, 'vendor_id' => $vendor->id]);
    $session = CheckoutSession::query()->create([
        'user_id' => $customer->id, 'total_amount_kobo' => 500_000, 'shipping_fee_kobo' => 0,
        'payment_method' => 'card', 'status' => 'paid', 'items_snapshot' => [],
        'delivery_address' => '1 Test Street', 'state' => 'Lagos', 'lga' => 'Eti-Osa',
        'recipient_name' => 'Test Customer', 'recipient_phone' => '08031234567',
    ]);
    $order = Order::query()->create([
        'customer_id' => $customer->id, 'vendor_id' => $vendor->id, 'product_id' => $product->id,
        'checkout_session_id' => $session->id, 'delivery_address' => '1 Test Street',
        'state' => 'Lagos', 'lga' => 'Eti-Osa', 'status' => OrderStatus::Delivered,
        'locked_price_kobo' => 500_000, 'commission_rate_percent' => '10.00',
        'commission_source' => 'default', 'commission_amount_kobo' => 50_000,
        'vendor_earning_amount_kobo' => 450_000, 'delivered_at' => now()->subDay(),
    ]);
    PaystackTransaction::query()->create([
        'user_id' => $customer->id, 'purpose' => 'order', 'checkout_session_id' => $session->id,
        'paystack_reference' => 'FMA_'.$order->id.'_original', 'amount_kobo' => 500_000,
        'currency' => 'NGN', 'status' => PaystackTransactionStatus::Success,
        'webhook_verified_at' => now(),
    ]);

    return $order;
}

function authorizationReceivedReturn(User $customer, User $admin, Order $order): object
{
    $returns = app(ReturnService::class);
    $request = $returns->open($customer, $order, ReturnReason::Damaged);
    $returns->approve($admin, $request);
    $returns->markInTransit($customer, $request);
    $returns->markReceived($admin, $request);

    return $request->refresh();
}

it('lets a support agent work the queue but not pay money back', function () {
    $agent = staffWith('Support Agent');

    // The desk: yes.
    expect($agent->can('returns.manage'))->toBeTrue();
    // The money: no.
    expect($agent->can('refunds.issue'))->toBeFalse();
});

it('lets a finance officer issue refunds', function () {
    $finance = staffWith('Finance Officer');

    expect($finance->can('refunds.issue'))->toBeTrue()
        ->and($finance->can('returns.manage'))->toBeTrue();
});

it('keeps logistics away from returns entirely', function () {
    $logistics = staffWith('Logistics Personnel');

    expect($logistics->can('returns.manage'))->toBeFalse()
        ->and($logistics->can('refunds.issue'))->toBeFalse();
});

it('refuses the refund endpoint to staff without the refund permission', function () {
    $order = authorizationRefundableOrder($this->customer);
    $request = authorizationReceivedReturn($this->customer, staffWith('Administrator'), $order);

    $agent = staffWith('Support Agent');

    $this->actingAs($agent)
        ->post(adminUrl("/returns/{$request->uuid}/refund"))
        ->assertForbidden();

    // Nothing moved.
    expect($this->gateway->refunds)->toBeEmpty()
        ->and(Refund::query()->count())->toBe(0);
});

it('lets an authorised admin issue the refund over HTTP', function () {
    $order = authorizationRefundableOrder($this->customer);
    $admin = staffWith('Finance Officer');
    $request = authorizationReceivedReturn($this->customer, $admin, $order);

    $this->actingAs($admin)
        ->post(adminUrl("/returns/{$request->uuid}/refund"))
        ->assertRedirect();

    expect(Refund::query()->where('status', Refund::STATUS_COMPLETED)->count())->toBe(1);
});

it('gives a customer no route to refund themselves', function () {
    $order = authorizationRefundableOrder($this->customer);
    $request = authorizationReceivedReturn($this->customer, staffWith('Finance Officer'), $order);

    // The admin routes live on the staff subdomain; a customer session there
    // is bounced by the portal guard rather than being allowed through.
    $this->actingAs($this->customer)
        ->post(adminUrl("/returns/{$request->uuid}/refund"))
        ->assertRedirect();

    expect(Refund::query()->count())->toBe(0);
});

it('keeps one customer return invisible to another customer', function () {
    $order = authorizationRefundableOrder($this->customer);
    $request = $this->returns->open($this->customer, $order, ReturnReason::Damaged);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get("/account/returns/{$request->uuid}")
        ->assertForbidden();
});
