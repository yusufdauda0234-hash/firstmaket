<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The admin order detail page.
 *
 * It had no coverage at all, which is how it shipped eager-loading a `plan`
 * relation that does not exist on Order — the relation is `savingsGoal`. The
 * page 500'd for every order, and nothing caught it because nothing ever
 * opened it.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->staff = User::factory()->create(['user_type' => UserType::Staff]);
    $this->staff->assignRole('Administrator');
    $this->staff->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 40_000_00]);
});

function adminOrderUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/orders'
        .($path === '' ? '' : '/'.ltrim($path, '/'));
}

it('opens the orders list', function () {
    $this->actingAs($this->staff)->get(adminOrderUrl())->assertOk();
});

it('opens an order that came from an ordinary checkout', function () {
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'vendor_id' => $this->product->vendor_id,
        'product_id' => $this->product->id,
        'savings_goal_id' => null,
    ]);

    $this->actingAs($this->staff)
        ->get(adminOrderUrl($order->uuid))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Orders/Show')
            // Null rather than missing: the page renders a dash for it.
            ->where('order.planUuid', null));
});

it('opens an order that came from a Pay Small Small plan', function () {
    // The case that broke: the page eager-loads the plan relation only when
    // there is one to load, so an order without a plan hid the typo.
    $goal = testPaidPlan($this->customer, $this->product);
    $order = app(SavingsGoalService::class)
        ->fulfil($this->customer, $goal)
        ->first();

    $this->actingAs($this->staff)
        ->get(adminOrderUrl($order->uuid))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.planUuid', $goal->uuid));
});

it('is closed to staff without the orders permission', function () {
    $agent = User::factory()->create(['user_type' => UserType::Staff]);
    $agent->assignRole('Support Agent');
    $agent->forceFill(['two_factor_confirmed_at' => now()])->save();

    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'vendor_id' => $this->product->vendor_id,
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($agent)->get(adminOrderUrl($order->uuid))->assertForbidden();
});

it('is closed to a customer', function () {
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'vendor_id' => $this->product->vendor_id,
        'product_id' => $this->product->id,
    ]);

    // Redirected, not 403: EnsureCorrectPortal ends the session and sends
    // them back to the storefront rather than letting a customer sit on an
    // admin URL at all.
    $this->actingAs($this->customer)
        ->get(adminOrderUrl($order->uuid))
        ->assertRedirect();
});
