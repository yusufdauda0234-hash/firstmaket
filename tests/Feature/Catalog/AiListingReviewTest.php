<?php

use App\Models\Setting;
use App\Modules\Catalog\Models\AiListingReview;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductStatusService;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AiListingAnalyzerContract;
use App\Shared\Enums\ProductStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 9 QA: the Listing Review Assistant is advisory only — it never
 * changes a product's status — and a configurable price-outlier threshold
 * feeds the default rule-based driver. QUEUE_CONNECTION=sync in phpunit.xml
 * means AnalyzeListingJob runs inline, so no queue faking is needed.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->category = Category::factory()->create();
    $this->vendor = VendorProfile::factory()->approved()->create();
});

function submitForReview(Category $category, VendorProfile $vendor, array $overrides = []): Product
{
    $product = Product::factory()->create(array_merge([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'description' => 'A perfectly ordinary, adequately described product for sale.',
    ], $overrides));

    app(ProductStatusService::class)->submit($product, $vendor->user);

    return $product->refresh();
}

it('writes an advisory review row when a listing is submitted, without changing product status', function () {
    $product = submitForReview($this->category, $this->vendor, ['price_kobo' => 50_000_00]);

    expect($product->status)->toBe(ProductStatus::PendingApproval);

    $review = AiListingReview::query()->where('product_id', $product->id)->firstOrFail();

    expect(in_array($review->status, ['clear', 'flagged'], true))->toBeTrue()
        ->and($review->model)->toBe('rule-based-v1');
});

it('flags a price that deviates beyond the configured outlier threshold', function () {
    Setting::set('ai.price_outlier_threshold_percent', 30.0, 'ai');

    Product::factory()->approved()->count(3)->create([
        'category_id' => $this->category->id,
        'price_kobo' => 10_000_00,
    ]);

    $product = submitForReview($this->category, $this->vendor, ['price_kobo' => 50_000_00]);

    $review = AiListingReview::query()->where('product_id', $product->id)->firstOrFail();
    $hasPriceFlag = collect($review->flags)->contains(fn (string $flag) => str_contains($flag, 'category average'));

    expect($review->status)->toBe('flagged')
        ->and($hasPriceFlag)->toBeTrue();
});

it('does not flag a price within the configured threshold', function () {
    Setting::set('ai.price_outlier_threshold_percent', 90.0, 'ai');

    Product::factory()->approved()->count(3)->create([
        'category_id' => $this->category->id,
        'price_kobo' => 10_000_00,
    ]);

    $product = submitForReview($this->category, $this->vendor, [
        'price_kobo' => 11_000_00,
        'description' => str_repeat('A well described, thorough product listing. ', 3),
    ]);

    $review = AiListingReview::query()->where('product_id', $product->id)->firstOrFail();
    $hasPriceFlag = collect($review->flags)->contains(fn (string $flag) => str_contains($flag, 'category average'));

    expect($hasPriceFlag)->toBeFalse();
});

it('still records a manual-review row when the bound analyzer fails, never blocking submission', function () {
    $this->app->bind(AiListingAnalyzerContract::class, fn () => new class implements AiListingAnalyzerContract
    {
        public function analyze(array $listing): array
        {
            throw new RuntimeException('provider unreachable');
        }
    });

    $product = submitForReview($this->category, $this->vendor);

    expect($product->status)->toBe(ProductStatus::PendingApproval);

    $review = AiListingReview::query()->where('product_id', $product->id)->firstOrFail();

    expect($review->status)->toBe('error')
        ->and($review->summary)->toContain('provider unreachable');
});

it('re-reviews a listing that returns to pending after a price change', function () {
    $product = Product::factory()->approved()->create([
        'vendor_id' => $this->vendor->id,
        'category_id' => $this->category->id,
    ]);

    app(ProductStatusService::class)->returnToPendingAfterPriceChange($product, $this->vendor->user);

    expect(AiListingReview::query()->where('product_id', $product->id)->count())->toBe(1);
});
