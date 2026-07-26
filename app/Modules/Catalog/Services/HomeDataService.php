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

    /**
     * @return array<int, array{name: string, slug: string}>
     */
    public function categories(): array
    {
        return Cache::remember('home.categories', self::CACHE_TTL_SECONDS, function () {
            $fromDatabase = Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['name', 'slug'])
                ->map(fn (Category $category) => ['name' => $category->name, 'slug' => $category->slug])
                ->all();

            // Before the CategorySeeder has run (fresh install), fall back to
            // the config list so navigation never renders empty.
            return $fromDatabase !== [] ? $fromDatabase : config('firstmaket.categories');
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
