<?php

use App\Models\User;
use App\Modules\Cart\Services\CartSummary;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\DeliveryRate;
use App\Modules\Orders\Services\DeliveryPricing;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Admin-managed delivery pricing: one fee per state, and a default row every
 * unpriced state falls back to.
 *
 * Nothing is read from config. Free delivery exists only where a threshold
 * has been set deliberately — zero, the default, means the fee is charged on
 * every order.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function deliveryRatesUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/settings/delivery-rates'
        .($path === '' ? '' : '/'.ltrim($path, '/'));
}

function ratesAdmin(): User
{
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');
    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $admin;
}

function makeRate(?string $state, int $feeKobo, int $threshold = 0): DeliveryRate
{
    // The migration seeds a default row, so replace it rather than adding a
    // second one — SQL treats every NULL as distinct, so both would survive.
    if ($state === null) {
        DeliveryRate::query()->whereNull('state')->delete();
    }

    return DeliveryRate::query()->create([
        'state' => $state,
        'fee_kobo' => $feeKobo,
        'free_threshold_kobo' => $threshold,
        'is_active' => true,
    ]);
}

// ── Pricing ─────────────────────────────────────────────────────────────

it('charges the fee set for the state', function () {
    makeRate(null, 150_000, 100_000_00);

    expect(app(DeliveryPricing::class)->feeKobo(10_000_00))->toBe(150_000);
});

it('prefers a state rate over the default', function () {
    makeRate(null, 150_000, 100_000_00);
    makeRate('Lagos', 50_000, 100_000_00);

    $pricing = app(DeliveryPricing::class);

    expect($pricing->feeKobo(10_000_00, 'Lagos'))->toBe(50_000)
        ->and($pricing->feeKobo(10_000_00, 'Kano'))->toBe(150_000);
});

it('charges the seeded default when no state rate applies', function () {
    // The migration guarantees a default row, so there is always a rate.
    expect(app(DeliveryPricing::class)->feeKobo(10_000_00))->toBe(150_000);
});

it('waives the fee above the threshold', function () {
    makeRate(null, 150_000, 50_000_00);

    $pricing = app(DeliveryPricing::class);

    expect($pricing->feeKobo(49_999_00))->toBe(150_000)
        ->and($pricing->feeKobo(50_000_00))->toBe(0);
});

it('never gives free delivery unless a threshold says so', function () {
    // A state rate with no threshold of its own charges on every order:
    // thresholds are not inherited, and there is no config figure behind
    // them, so free delivery only exists where somebody set it.
    makeRate(null, 150_000, 50_000_00);
    makeRate('Kano', 200_000);

    expect(app(DeliveryPricing::class)->feeKobo(50_000_00, 'Kano'))->toBe(200_000)
        ->and(app(DeliveryPricing::class)->feeKobo(500_000_00, 'Kano'))->toBe(200_000);
});

it('lets a state refuse free delivery while others offer it', function () {
    makeRate(null, 150_000, 50_000_00);
    makeRate('Borno', 500_000, 0);

    expect(app(DeliveryPricing::class)->feeKobo(500_000_00, 'Borno'))->toBe(500_000)
        ->and(app(DeliveryPricing::class)->feeKobo(500_000_00, 'Kano'))->toBe(0);
});

it('charges a state fee on every order by default', function () {
    // The reported case: a Gombe rate that was being waived on large orders
    // by a threshold inherited from config. Nothing is inherited now, so the
    // fee applies whatever the order is worth.
    makeRate('Gombe', 200_000);

    $pricing = app(DeliveryPricing::class);

    expect($pricing->freeThresholdKobo('Gombe'))->toBe(0)
        ->and($pricing->feeKobo(14_999_00, 'Gombe'))->toBe(200_000)
        ->and($pricing->feeKobo(93_000_00, 'Gombe'))->toBe(200_000);
});

it('reports no free-delivery headline when nothing offers it', function () {
    makeRate(null, 150_000);

    expect(app(DeliveryPricing::class)->lowestFreeThresholdKobo())->toBe(0);
});

it('headlines the lowest threshold on offer', function () {
    makeRate(null, 150_000, 100_000_00);
    makeRate('Lagos', 25_000, 20_000_00);

    expect(app(DeliveryPricing::class)->lowestFreeThresholdKobo())->toBe(20_000_00);
});

it('charges nothing on an empty basket', function () {
    makeRate(null, 150_000, 100_000_00);

    expect(app(DeliveryPricing::class)->feeKobo(0))->toBe(0);
});

// ── Checkout uses it ────────────────────────────────────────────────────

it('quotes the state rate in the cart summary', function () {
    makeRate(null, 150_000, 100_000_00);
    makeRate('Lagos', 25_000, 100_000_00);

    $product = Product::factory()->approved()->create(['price_kobo' => 10_000_00]);
    $lines = collect([['cartItemId' => null, 'product' => $product, 'quantity' => 1]]);

    expect(CartSummary::fromLines($lines, 'Lagos')->shippingKobo)->toBe(25_000)
        ->and(CartSummary::fromLines($lines)->shippingKobo)->toBe(150_000);
});

it('reports the threshold that applies to the state', function () {
    makeRate(null, 150_000, 100_000_00);
    makeRate('Lagos', 25_000, 20_000_00);

    $product = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);
    $lines = collect([['cartItemId' => null, 'product' => $product, 'quantity' => 1]]);

    expect(CartSummary::fromLines($lines, 'Lagos')->toArray()['freeShippingThresholdKobo'])
        ->toBe(20_000_00);
});

// ── Admin ───────────────────────────────────────────────────────────────

/*
 * The screen itself, not just the endpoints behind it.
 *
 * The other tests here post to the controller and inspect the database, so
 * when the page started reading a `configFallback` prop the controller no
 * longer sent, nothing failed — the endpoints were fine and the render
 * returned 200 because React never runs server-side. The screen was blank in
 * a browser for anyone who opened it.
 */
it('renders the rates screen with every prop the page reads', function () {
    makeRate('Lagos', 150_000);

    $this->actingAs(ratesAdmin())
        ->get(deliveryRatesUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/DeliveryRates')
            ->has('rates')
            ->has('availableStates')
            ->has('hasDefault')
            ->has('templates'));
});

it('renders the rates screen when there are no rates at all', function () {
    DeliveryRate::query()->delete();

    // The empty state has its own copy, and its own chance to read something
    // that is not there.
    $this->actingAs(ratesAdmin())
        ->get(deliveryRatesUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rates', 0)->where('hasDefault', false));
});

it('tells the page whether a default rate exists', function () {
    DeliveryRate::query()->delete();

    $this->actingAs(ratesAdmin())
        ->get(deliveryRatesUrl())
        ->assertInertia(fn ($page) => $page->where('hasDefault', false));

    makeRate(null, 150_000);

    // Without one, every state with no row of its own ships free — which is
    // what the banner on the page warns about.
    $this->actingAs(ratesAdmin())
        ->get(deliveryRatesUrl())
        ->assertInertia(fn ($page) => $page->where('hasDefault', true));
});

it('lets an admin add a rate for a state', function () {
    $this->actingAs(ratesAdmin())
        ->post(deliveryRatesUrl(), [
            'state' => 'Lagos',
            'fee_naira' => 1500,
            'free_threshold_naira' => 20000,
            'is_active' => true,
        ])
        ->assertSessionDoesntHaveErrors();

    $rate = DeliveryRate::query()->where('state', 'Lagos')->firstOrFail();

    expect($rate->fee_kobo)->toBe(150_000)
        ->and($rate->totalKobo())->toBe(150_000)
        ->and($rate->free_threshold_kobo)->toBe(20_000_00);
});

it('refuses a second rate for the same state', function () {
    makeRate('Lagos', 25_000);

    $this->actingAs(ratesAdmin())
        ->post(deliveryRatesUrl(), ['state' => 'Lagos', 'fee_naira' => 200, 'is_active' => true])
        ->assertSessionHasErrors('state');
});

it('refuses a state that is not Nigerian', function () {
    $this->actingAs(ratesAdmin())
        ->post(deliveryRatesUrl(), ['state' => 'Atlantis', 'fee_naira' => 200, 'is_active' => true])
        ->assertSessionHasErrors('state');
});

it('defaults a new rate to charging on every order', function () {
    // A blank threshold posts as 0, which means never free. It does not
    // inherit anything — there is nothing to inherit from.
    $this->actingAs(ratesAdmin())
        ->post(deliveryRatesUrl(), ['state' => 'Kano', 'fee_naira' => 200, 'is_active' => true])
        ->assertSessionDoesntHaveErrors();

    expect(DeliveryRate::query()->where('state', 'Kano')->value('free_threshold_kobo'))->toBe(0);
});

it('refuses a second default rate', function () {
    // SQL treats every NULL as distinct, so a unique index does not stop two
    // state-less rows. The controller has to.
    makeRate(null, 150_000);

    $this->actingAs(ratesAdmin())
        ->post(deliveryRatesUrl(), ['state' => '', 'fee_naira' => 200, 'is_active' => true])
        ->assertSessionHasErrors();

    expect(DeliveryRate::query()->whereNull('state')->count())->toBe(1);
});

it('deletes a state rate so it falls back to the default', function () {
    makeRate(null, 150_000);
    $lagos = makeRate('Lagos', 25_000);

    $this->actingAs(ratesAdmin())
        ->delete(deliveryRatesUrl($lagos->uuid))
        ->assertSessionDoesntHaveErrors();

    expect(DeliveryRate::query()->where('state', 'Lagos')->exists())->toBeFalse()
        ->and(app(DeliveryPricing::class)->feeKobo(10_000_00, 'Lagos'))->toBe(150_000);
});

it('will not delete the default rate', function () {
    $default = makeRate(null, 150_000);

    // Every unpriced state uses it and there is nothing behind it, so
    // deleting would silently make delivery free nationwide.
    $this->actingAs(ratesAdmin())
        ->delete(deliveryRatesUrl($default->uuid))
        ->assertSessionHasErrors('rate');

    expect(DeliveryRate::query()->whereKey($default->id)->exists())->toBeTrue();
});

it('will not switch the default rate off', function () {
    $default = makeRate(null, 150_000);

    $this->actingAs(ratesAdmin())
        ->post(deliveryRatesUrl('bulk'), ['action' => 'deactivate', 'uuids' => [$default->uuid]])
        ->assertSessionHasErrors();

    expect($default->fresh()->is_active)->toBeTrue();
});

it('will not switch the default off from its own form either', function () {
    $default = makeRate(null, 150_000);

    $this->actingAs(ratesAdmin())
        ->put(deliveryRatesUrl($default->uuid), [
            'state' => '',
            'fee_naira' => 1500,
            'is_active' => false,
        ])
        ->assertSessionHasErrors('rate');

    expect($default->fresh()->is_active)->toBeTrue();
});

it('switches several rates off at once', function () {
    makeRate(null, 150_000);
    $lagos = makeRate('Lagos', 25_000);
    $kano = makeRate('Kano', 30_000);

    $this->actingAs(ratesAdmin())
        ->post(deliveryRatesUrl('bulk'), [
            'action' => 'deactivate',
            'uuids' => [$lagos->uuid, $kano->uuid],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($lagos->fresh()->is_active)->toBeFalse()
        ->and($kano->fresh()->is_active)->toBeFalse();
});

it('is closed to staff without the fees permission', function () {
    $agent = User::factory()->create(['user_type' => UserType::Staff]);
    $agent->assignRole('Support Agent');
    $agent->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($agent)->get(deliveryRatesUrl())->assertForbidden();
});

// ── What the shopper is quoted ──────────────────────────────────────────

it('quotes the cart against the saved delivery state', function () {
    makeRate(null, 150_000);
    makeRate('Lagos', 25_000);

    $customer = User::factory()->create(['phone_verified_at' => now()]);
    $customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $customer->id, 'default_state' => 'Lagos']);

    $product = Product::factory()->approved()->create(['price_kobo' => 10_000_00]);

    $this->actingAs($customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1])
        ->assertRedirect();

    // Priced against where it is actually going, not the national default —
    // otherwise the fee changes between the cart and checkout.
    $this->actingAs($customer)
        ->get(route('cart.index'))
        ->assertInertia(fn ($page) => $page->where('summary.shippingKobo', 25_000));
});

it('flags the cart fee as an estimate for a guest', function () {
    makeRate(null, 150_000);

    $customer = User::factory()->create(['phone_verified_at' => now()]);
    $customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $customer->id]);

    $product = Product::factory()->approved()->create(['price_kobo' => 10_000_00]);

    $this->actingAs($customer)
        ->post(route('cart.items.store'), ['product_uuid' => $product->uuid, 'quantity' => 1]);

    // Nobody has said where it is going, so the figure is a national default
    // that checkout may revise. The page is told which of the two it shows.
    $this->actingAs($customer)
        ->get(route('cart.index'))
        ->assertInertia(fn ($page) => $page->where('deliveryIsEstimate', true));
});
