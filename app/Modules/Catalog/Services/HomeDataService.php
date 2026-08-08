<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Assembles the public home page data (Sprint 0 shell, fed by the Sprint 3
 * catalog). Approved products only, cached briefly — this is the
 * highest-traffic anonymous page.
 */
class HomeDataService
{
    private const CACHE_TTL_SECONDS = 300;

    /** Everything this service caches, so it can all be dropped at once. */
    private const CACHE_KEYS = [
        'home.categories.v3',
        'home.featured',
        'home.newest',
    ];

    /**
     * Drop the cached home page data.
     *
     * The TTL alone is not enough. A vendor who lists a product, or staff who
     * approve one, expect to see it on the storefront straight away — waiting
     * up to five minutes reads as the product having failed to save. Called
     * whenever a product or category changes.
     */
    public static function forget(): void
    {
        foreach (self::CACHE_KEYS as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Top-level categories, each carrying its own sub-categories.
     *
     * Sub-categories are nested rather than listed as peers — putting "Phones"
     * beside "Electronics" would present them as equals. They are included at
     * all because the header menu drills into a category, and it had nothing
     * to drill into: hovering a parent showed an empty panel.
     *
     * @return array<int, array{name: string, slug: string, children: array<int, array{name: string, slug: string}>}>
     */
    public function categories(): array
    {
        // v3: the shape gained `children`, so the v2 entries must not be read.
        return Cache::remember('home.categories.v3', self::CACHE_TTL_SECONDS, function () {
            $active = Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'parent_id']);

            $byParent = $active->groupBy('parent_id');

            $fromDatabase = $active
                ->whereNull('parent_id')
                ->map(fn (Category $category) => [
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'children' => $byParent->get($category->id, collect())
                        ->map(fn (Category $child) => ['name' => $child->name, 'slug' => $child->slug])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();

            if ($fromDatabase !== []) {
                return $fromDatabase;
            }

            // Before anyone has built the catalogue (fresh install), fall back to
            // the config list so navigation never renders empty. It carries no
            // sub-categories, so they are filled in empty rather than left
            // missing — the menu reads `children` on every entry.
            return array_map(
                fn (array $category) => $category + ['children' => []],
                config('firstmaket.categories'),
            );
        });
    }

    /**
     * Newest approved products — the "Featured" strip until Featured-tier
     * posting fees launch, then those get priority.
     *
     * @return array<int, array<string, mixed>>
     */
    public function featuredProducts(): array
    {
        return Cache::remember('home.featured', self::CACHE_TTL_SECONDS, function () {
            $featured = Product::query()
                ->approved()
                ->whereHas('postingFees', fn ($query) => $query->where('tier', 'featured'))
                ->with(['images', 'category:id,slug', 'vendor:id,business_name'])
                ->latest('approved_at')
                ->limit(8)
                ->get();

            // Until vendors buy Featured-tier placements, fall back to the
            // newest approved products so the hero carousel and deals strips
            // never render empty.
            if ($featured->isEmpty()) {
                $featured = Product::query()
                    ->approved()
                    ->with(['images', 'category:id,slug', 'vendor:id,business_name'])
                    ->latest('approved_at')
                    ->limit(8)
                    ->get();
            }

            return $this->presentProducts($featured);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function newestProducts(): array
    {
        return Cache::remember('home.newest', self::CACHE_TTL_SECONDS, function () {
            return $this->presentProducts(
                Product::query()
                    ->approved()
                    ->with(['images', 'category:id,slug', 'vendor:id,business_name'])
                    ->latest('approved_at')
                    ->limit(12)
                    ->get()
            );
        });
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function presentProducts($products): array
    {
        return $products->map(fn (Product $product) => [
            'uuid' => $product->uuid,
            'name' => $product->name,
            'slug' => $product->slug,
            'priceKobo' => $product->price_kobo,
            'compareAtPriceKobo' => $product->compare_at_price_kobo,
            'ratingAverage' => $product->rating_average !== null ? (float) $product->rating_average : null,
            'ratingCount' => $product->rating_count,
            'imageUrl' => $product->primaryImageUrl(),
            'imageUrls' => $product->images->map(fn ($image) => $image->url())->values()->all(),
            'categorySlug' => $product->category->slug,
            'description' => Str::limit($product->description, 600),
            'stockQuantity' => $product->stock_quantity,
            'vendorName' => $product->vendor->business_name,
        ])->all();
    }
}
