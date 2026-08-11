<?php

use App\Models\Setting;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Commands\RecalculateVendorRatings;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Models\VendorRating;
use App\Modules\Vendor\Models\VendorRatingSnapshot;
use App\Modules\Vendor\Services\VendorRatingService;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\VendorRatingTierSeeder;

/**
 * Phase 2D vendor tiers.
 *
 * The requirement the plan states outright is that the calculation be
 * reproducible, so that is what most of this file is about: the same stored
 * facts must always produce the same score, and every threshold must be a
 * value staff can change rather than a constant.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(VendorRatingTierSeeder::class);

    $this->ratings = app(VendorRatingService::class);
    $this->vendor = VendorProfile::factory()->create(['status' => VendorStatus::Approved]);
    $this->category = Category::factory()->create();
});

function vendorOrders(VendorProfile $vendor, Category $category, OrderStatus $status, int $count): void
{
    $product = Product::factory()->approved()->create([
        'category_id' => $category->id,
        'vendor_id' => $vendor->id,
    ]);

    for ($i = 0; $i < $count; $i++) {
        Order::query()->create([
            'customer_id' => \App\Models\User::factory()->create()->id,
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'delivery_address' => '1 Test Street',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
            'status' => $status,
            'locked_price_kobo' => 100_000,
            'commission_rate_percent' => '10.00',
            'commission_source' => 'default',
            'commission_amount_kobo' => 10_000,
            'vendor_earning_amount_kobo' => 90_000,
            'delivered_at' => $status === OrderStatus::Delivered ? now()->subDay() : null,
        ]);
    }
}

it('gives the same answer every time it is run on the same data', function () {
    vendorOrders($this->vendor, $this->category, OrderStatus::Delivered, 20);

    $first = $this->ratings->recalculate($this->vendor);
    $firstScore = $first->score;
    $firstTier = $first->vendor_rating_tier_id;

    // Nothing changed, so nothing about the answer may change either.
    $second = $this->ratings->recalculate($this->vendor->fresh());

    expect($second->score)->toBe($firstScore)
        ->and($second->vendor_rating_tier_id)->toBe($firstTier);
});

it('scores a clean fulfilment record highly and a rejection-heavy one poorly', function () {
    $good = $this->vendor;
    vendorOrders($good, $this->category, OrderStatus::Delivered, 20);

    $bad = VendorProfile::factory()->create(['status' => VendorStatus::Approved]);
    vendorOrders($bad, $this->category, OrderStatus::Delivered, 10);
    vendorOrders($bad, $this->category, OrderStatus::VendorRejected, 10);

    expect($this->ratings->recalculate($good)->score)
        ->toBeGreaterThan($this->ratings->recalculate($bad)->score);
});

it('treats a vendor with too few orders as unproven rather than bad', function () {
    vendorOrders($this->vendor, $this->category, OrderStatus::Delivered, 2);

    // Two orders is noise, not a rating — the neutral midpoint, not zero.
    expect($this->ratings->recalculate($this->vendor)->score)->toBe(50);
});

it('lets staff change the weightings without a deploy', function () {
    vendorOrders($this->vendor, $this->category, OrderStatus::Delivered, 10);
    vendorOrders($this->vendor, $this->category, OrderStatus::VendorRejected, 10);

    $before = $this->ratings->recalculate($this->vendor)->score;

    // Care much more about rejections.
    Setting::set('vendor_rating.weight_rejection', 80, 'vendor_rating');
    Setting::set('vendor_rating.weight_fulfilment', 5, 'vendor_rating');

    expect($this->ratings->recalculate($this->vendor->fresh())->score)->not->toBe($before);
});

it('withholds a tier whose rejection ceiling the vendor breaches, however good the score', function () {
    // Enough delivered orders for Silver, but far too many rejections.
    vendorOrders($this->vendor, $this->category, OrderStatus::Delivered, 30);
    vendorOrders($this->vendor, $this->category, OrderStatus::VendorRejected, 20);

    $rating = $this->ratings->recalculate($this->vendor);

    expect($rating->tier?->name)->toBe('Bronze');
});

it('records a snapshot when the tier changes and not when it does not', function () {
    vendorOrders($this->vendor, $this->category, OrderStatus::Delivered, 20);

    $this->ratings->recalculate($this->vendor);
    expect(VendorRatingSnapshot::query()->count())->toBe(1);

    // Same data, same tier — history should not grow on every nightly run.
    $this->ratings->recalculate($this->vendor->fresh());
    expect(VendorRatingSnapshot::query()->count())->toBe(1);
});

it('stores the numbers behind the score so a vendor can be told why', function () {
    vendorOrders($this->vendor, $this->category, OrderStatus::Delivered, 12);
    vendorOrders($this->vendor, $this->category, OrderStatus::VendorRejected, 3);

    $rating = $this->ratings->recalculate($this->vendor);

    expect($rating->delivered_orders)->toBe(12)
        ->and($rating->rejected_orders)->toBe(3);
});

it('recalculates every approved vendor from the command', function () {
    vendorOrders($this->vendor, $this->category, OrderStatus::Delivered, 12);

    $this->artisan(RecalculateVendorRatings::class)->assertSuccessful();

    expect(VendorRating::query()->where('vendor_id', $this->vendor->id)->exists())->toBeTrue();
});
