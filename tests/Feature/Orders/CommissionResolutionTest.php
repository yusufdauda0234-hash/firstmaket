<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\CommissionRule;
use App\Modules\Orders\Services\CommissionRate;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Which rule sets the commission on a sale.
 *
 * Most specific wins: a rate negotiated with the vendor, then the category's
 * rate, then the platform default. The rule that won is snapshotted onto the
 * order alongside the rate, so the admin screen can say why a figure is what
 * it is without re-deriving it under today's rules.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->product = Product::factory()->approved()->create(['price_kobo' => 2_000_00]);
});

/** A category-scoped rule covering any price. */
function setCategoryRate(int $categoryId, string $percent): CommissionRule
{
    return CommissionRule::query()->create([
        'scope_type' => 'category',
        'scope_id' => $categoryId,
        'rate_percent' => $percent,
        'is_active' => true,
    ]);
}

/** A rule banded to one slice of the price range. */
function bandedRule(string $scopeType, ?int $scopeId, int $minKobo, ?int $maxKobo, string $percent, array $extra = []): CommissionRule
{
    return CommissionRule::query()->create(array_merge([
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'min_price_kobo' => $minKobo,
        'max_price_kobo' => $maxKobo,
        'rate_percent' => $percent,
        'is_active' => true,
    ], $extra));
}

// ── Resolution ──────────────────────────────────────────────────────────

it('takes nothing when no rule matches and no default is set', function () {
    // The platform default is 0 out of the box. FirstMaket earning a cut on
    // a sale nobody configured a rate for is a decision, and it has to be
    // made deliberately on the commissions screen.
    $rate = CommissionRate::for($this->product);

    expect($rate->percent)->toBe(0.0)
        ->and($rate->source)->toBe('default')
        ->and($rate->onKobo(2_000_00))->toBe(0);
});

it('falls back to the platform default once one is set', function () {
    Setting::set('orders.default_commission_percent', 10);

    $rate = CommissionRate::for($this->product);

    expect($rate->percent)->toBe(10.0)
        ->and($rate->source)->toBe('default')
        ->and($rate->explain())->toContain('Platform default');
});

it('prefers a category rate over the default', function () {
    setCategoryRate($this->product->category_id, '5.00');

    $rate = CommissionRate::for($this->product->refresh());

    expect($rate->percent)->toBe(5.0)
        ->and($rate->source)->toBe('rule');
});

it('prefers a vendor rule over a category one', function () {
    setCategoryRate($this->product->category_id, '5.00');
    bandedRule('vendor', $this->product->vendor_id, 0, null, '3.50');

    $rate = CommissionRate::for($this->product->refresh());

    expect($rate->percent)->toBe(3.5)
        ->and($rate->source)->toBe('rule')
        ->and($rate->explain())->toContain($this->product->vendor->business_name);
});

it('honours a zero-rate rule rather than treating it as absent', function () {
    // A vendor onboarded commission-free is a real arrangement.
    setCategoryRate($this->product->category_id, '5.00');
    bandedRule('vendor', $this->product->vendor_id, 0, null, '0.00');

    $rate = CommissionRate::for($this->product->refresh());

    expect($rate->percent)->toBe(0.0)
        ->and($rate->source)->toBe('rule')
        ->and($rate->onKobo(2_000_00))->toBe(0);
});

it('ignores a rule that has been switched off', function () {
    setCategoryRate($this->product->category_id, '5.00')->update(['is_active' => false]);

    expect(CommissionRate::for($this->product->refresh())->source)->toBe('default');
});

it('computes commission on the unit price', function () {
    Setting::set('orders.default_commission_percent', 10);

    expect(CommissionRate::for($this->product)->onKobo(2_000_00))->toBe(200_00);
});

// ── Snapshotting ────────────────────────────────────────────────────────

it('records which rule set the rate on the order', function () {
    bandedRule('vendor', $this->product->vendor_id, 0, null, '6.00');

    $order = testOrder($this->customer, $this->product->refresh());

    expect($order->commission_source)->toBe('rule')
        ->and($order->commission_rate_percent)->toBe('6.00')
        ->and($order->commission_amount_kobo)->toBe(120_00)
        ->and($order->vendor_earning_amount_kobo)->toBe(1_880_00);
});

it('keeps the snapshot when the rate changes afterwards', function () {
    Setting::set('orders.default_commission_percent', 10);

    $order = testOrder($this->customer, $this->product);

    bandedRule('vendor', $this->product->vendor_id, 0, null, '1.00');

    // The vendor was owed what was agreed on the day, not what is agreed now.
    expect($order->refresh()->commission_rate_percent)->toBe('10.00')
        ->and($order->commission_source)->toBe('default');
});

// ── Price bands: the ₦500 vs ₦5,000 problem ─────────────────────────────

it('charges the same category differently at different prices', function () {
    // Two pieces of electrical wire, same category, ten times apart in price.
    // A flat percentage earns ₦50 on one and ₦500 on the other while both
    // cost the same to process — which is the reason bands exist.
    bandedRule('category', $this->product->category_id, 0, 1_000_00, '20.00');
    bandedRule('category', $this->product->category_id, 1_000_00, null, '8.00');

    $product = $this->product->refresh();

    expect(CommissionRate::for($product, 500_00)->percent)->toBe(20.0)
        ->and(CommissionRate::for($product, 5_000_00)->percent)->toBe(8.0);
});

it('treats the top of a band as belonging to the next one up', function () {
    bandedRule('category', $this->product->category_id, 0, 1_000_00, '20.00');
    bandedRule('category', $this->product->category_id, 1_000_00, null, '8.00');

    $product = $this->product->refresh();

    // Exclusive ceiling, so exactly ₦1,000 is the higher band and no price
    // ever matches two touching bands.
    expect(CommissionRate::for($product, 999_99)->percent)->toBe(20.0)
        ->and(CommissionRate::for($product, 1_000_00)->percent)->toBe(8.0);
});

it('prefers a narrower band over a catch-all in the same scope', function () {
    bandedRule('category', $this->product->category_id, 0, null, '10.00');
    bandedRule('category', $this->product->category_id, 0, 1_000_00, '20.00');

    expect(CommissionRate::for($this->product->refresh(), 500_00)->percent)->toBe(20.0);
});

it('prefers a product rule over a vendor rule over a category rule', function () {
    bandedRule('category', $this->product->category_id, 0, null, '10.00');
    expect(CommissionRate::for($this->product->refresh(), 500_00)->percent)->toBe(10.0);

    bandedRule('vendor', $this->product->vendor_id, 0, null, '7.00');
    expect(CommissionRate::for($this->product->refresh(), 500_00)->percent)->toBe(7.0);

    bandedRule('product', $this->product->id, 0, null, '3.00');
    expect(CommissionRate::for($this->product->refresh(), 500_00)->percent)->toBe(3.0);
});

it('never collects more than the ceiling', function () {
    bandedRule('global', null, 0, null, '10.00', ['max_commission_kobo' => 5_000_00]);

    expect(CommissionRate::for($this->product->refresh(), 500_000_00)->onKobo(500_000_00))->toBe(5_000_00);
});

it('ignores a rule whose band excludes the price', function () {
    bandedRule('category', $this->product->category_id, 10_000_00, null, '2.00');

    // Priced below the band, so it falls through to the default.
    expect(CommissionRate::for($this->product->refresh(), 500_00)->source)->toBe('default');
});

it('explains a banded rule in words', function () {
    bandedRule('category', $this->product->category_id, 0, 1_000_00, '20.00');

    expect(CommissionRate::for($this->product->refresh(), 500_00)->explain())
        ->toContain('between')
        ->toContain('1,000');
});

// ── Admin ───────────────────────────────────────────────────────────────

function rulesAdmin(): User
{
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $admin;
}

function rulesUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/settings/commissions'
        .($path === '' ? '' : '/'.ltrim($path, '/'));
}

it('lets an admin add a banded rule', function () {
    $this->actingAs(rulesAdmin())
        ->post(rulesUrl(), [
            'scope_type' => 'category',
            'scope_id' => $this->product->category_id,
            'min_price_naira' => 0,
            'max_price_naira' => 1000,
            'rate_percent' => 20,
        ])
        ->assertSessionDoesntHaveErrors();

    $rule = CommissionRule::query()->latest('id')->firstOrFail();

    expect($rule->max_price_kobo)->toBe(1_000_00)
        ->and($rule->rate_percent)->toBe('20.00');
});

it('refuses a band whose top is below its bottom', function () {
    $this->actingAs(rulesAdmin())
        ->post(rulesUrl(), [
            'scope_type' => 'global',
            'min_price_naira' => 5000,
            'max_price_naira' => 1000,
            'rate_percent' => 10,
        ])
        ->assertSessionHasErrors('max_price_naira');
});

it('refuses a scoped rule with nothing chosen', function () {
    $this->actingAs(rulesAdmin())
        ->post(rulesUrl(), ['scope_type' => 'category', 'rate_percent' => 10])
        ->assertSessionHasErrors('scope_id');
});

it('refuses a rule pointing at something that does not exist', function () {
    // A rule scoped to a missing row would never match, which is worse than
    // refusing it outright.
    $this->actingAs(rulesAdmin())
        ->post(rulesUrl(), ['scope_type' => 'category', 'scope_id' => 99999, 'rate_percent' => 10])
        ->assertSessionHasErrors('scope_id');
});

it('refuses a rate over 100 per cent', function () {
    $this->actingAs(rulesAdmin())
        ->post(rulesUrl(), ['scope_type' => 'global', 'rate_percent' => 150])
        ->assertSessionHasErrors('rate_percent');
});

it('lets an admin delete a rule', function () {
    $rule = setCategoryRate($this->product->category_id, '5.00');

    $this->actingAs(rulesAdmin())->delete(rulesUrl($rule->uuid))->assertSessionHas('success');

    expect(CommissionRate::for($this->product->refresh())->source)->toBe('default');
});
