<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Affiliates\Models\AffiliateAttribution;
use App\Modules\Affiliates\Models\AffiliateClick;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Catalog\Events\ProductApproved;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\OrderStatus;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * These tests are about attribution and commission arithmetic.
 *
 * Phase 3A added a velocity heuristic that holds a conversion for review
 * when it lands within minutes of the attribution — realistic in production,
 * where signup and delivery are days apart, but every test here creates both
 * in the same instant. Switched off so the money rules can be checked on
 * their own; the heuristic has its own tests in
 * tests/Feature/Affiliates/AdvancedAffiliateTest.php.
 */
beforeEach(function () {
    Setting::set('affiliates.fraud_min_minutes_to_convert', 0, 'affiliates');
});

it('submits an affiliate application and shows pending status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/account/affiliate', ['display_name' => 'Naija Finds'])
        ->assertRedirect();

    $this->actingAs($user)
        ->get('/account/affiliate')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Affiliate')
            ->where('application.status', 'pending')
            ->where('application.displayName', 'Naija Finds'));
});

it('approves an affiliate with a protected link and deduplicates repeat clicks', function () {
    $applicant = User::factory()->create();
    $admin = User::factory()->create();
    $affiliate = app(AffiliateService::class)->apply($applicant, 'Naija Finds');
    $link = app(AffiliateService::class)->approve($affiliate, $admin);

    expect($link->code)->toMatch('/^[A-Z0-9]{16}$/')
        ->and($link->code)->not->toBe((string) $affiliate->id);

    // Links are signed since Phase 3A, so a capture carries the signature the
    // affiliate's own dashboard hands out with the URL.
    app(AffiliateService::class)->capture($link->code, '127.0.0.1', 'Test Browser', $link->signature);
    app(AffiliateService::class)->capture($link->code, '127.0.0.1', 'Test Browser', $link->signature);

    expect(AffiliateClick::query()->where('affiliate_link_id', $link->id)->count())->toBe(1);
});

it('does not track suspended affiliate links', function () {
    $applicant = User::factory()->create();
    $admin = User::factory()->create();
    $affiliate = app(AffiliateService::class)->apply($applicant, 'Naija Finds');
    $link = app(AffiliateService::class)->approve($affiliate, $admin);
    $affiliate->update(['status' => 'suspended']);

    // Signature passed, so suspension is what refuses this — not a missing token.
    expect(app(AffiliateService::class)->capture($link->code, '127.0.0.1', 'Test Browser', $link->signature))->toBeNull()
        ->and(AffiliateClick::query()->count())->toBe(0);
});

it('attributes a signup once and creates commission only after delivery confirmation', function () {
    $affiliateUser = User::factory()->create();
    $customer = User::factory()->create();
    $admin = User::factory()->create();
    $affiliate = app(AffiliateService::class)->apply($affiliateUser, 'Naija Finds');
    $link = app(AffiliateService::class)->approve($affiliate, $admin);

    app(AffiliateService::class)->attributeSignup($customer, $link->id);
    app(AffiliateService::class)->attributeSignup($customer, $link->id);

    expect(AffiliateAttribution::query()->where('user_id', $customer->id)->count())->toBe(1)
        ->and(AffiliateCommission::query()->count())->toBe(0);

    $product = Product::factory()->approved()->create(['price_kobo' => 100_000]);
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'product_id' => $product->id,
        'locked_price_kobo' => 100_000,
        'status' => OrderStatus::Delivered,
    ]);

    app(AffiliateService::class)->qualifyDeliveredOrder($order);
    app(AffiliateService::class)->qualifyDeliveredOrder($order);

    expect(AffiliateCommission::query()->count())->toBe(1)
        ->and(AffiliateCommission::query()->first()->amount_kobo)->toBe(5_000);
});

it('qualifies a referred vendor on the first approved product only', function () {
    $affiliateUser = User::factory()->create();
    $vendorUser = User::factory()->create();
    $admin = User::factory()->create();
    $affiliate = app(AffiliateService::class)->apply($affiliateUser, 'Naija Finds');
    $link = app(AffiliateService::class)->approve($affiliate, $admin);
    app(AffiliateService::class)->attributeSignup($vendorUser, $link->id);

    $vendor = VendorProfile::factory()->approved()->create(['user_id' => $vendorUser->id]);
    $firstProduct = Product::factory()->approved()->create([
        'vendor_id' => $vendor->id,
        'price_kobo' => 200_000,
    ]);
    $secondProduct = Product::factory()->approved()->create([
        'vendor_id' => $vendor->id,
        'price_kobo' => 300_000,
    ]);

    event(new ProductApproved($firstProduct));
    event(new ProductApproved($firstProduct));
    event(new ProductApproved($secondProduct));

    expect(AffiliateConversion::query()->where('conversion_type', 'vendor_product')->count())->toBe(1)
        ->and(AffiliateCommission::query()->whereHas('conversion', fn ($query) => $query->where('conversion_type', 'vendor_product'))->count())->toBe(1)
        ->and(AffiliateCommission::query()->whereHas('conversion', fn ($query) => $query->where('conversion_type', 'vendor_product'))->first()->amount_kobo)->toBe(10_000);
});

it('uses the configured affiliate commission percentage for new conversions', function () {
    Setting::set('affiliates.commission_percent', 7.5, 'growth');
    $affiliateUser = User::factory()->create();
    $customer = User::factory()->create();
    $admin = User::factory()->create();
    $affiliate = app(AffiliateService::class)->apply($affiliateUser, 'Naija Finds');
    $link = app(AffiliateService::class)->approve($affiliate, $admin);
    app(AffiliateService::class)->attributeSignup($customer, $link->id);
    $product = Product::factory()->approved()->create(['price_kobo' => 100_000]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id, 'locked_price_kobo' => 100_000, 'status' => OrderStatus::Delivered]);

    app(AffiliateService::class)->qualifyDeliveredOrder($order);

    expect(AffiliateCommission::query()->first()->amount_kobo)->toBe(7_500);
});
