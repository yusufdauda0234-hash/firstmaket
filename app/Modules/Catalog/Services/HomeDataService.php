<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HeroSlide;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductViewCount;
use App\Modules\Catalog\Models\SearchTerm;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Setting;
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
     * How many products a home-page section shows.
     *
     * Merchandising, not engineering — how many tiles sit on the front page is
     * the kind of thing a shop wants to try three ways on a Friday, so it
     * lives in settings rather than in a deploy. Clamped, because a section of
     * 5,000 tiles is a mistake, not a choice.
     *
     * Note the cache: a change here shows up when the home cache next turns
     * over, or immediately if a product edit clears it.
     */
    private static function sectionSize(string $section, int $default): int
    {
        return max(1, min(48, (int) Setting::get('home.'.$section.'_limit', $default)));
    }

    /** Everything this service caches, so it can all be dropped at once. */
    private const CACHE_KEYS = [
        'home.categories.v3',
        'home.featured',
        'home.newest',
        'home.campaigns',
        'home.trending',
        'home.trending_searches',
        'home.hero_slides',
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

    public static function forgetCampaigns(): void
    {
        Cache::forget('home.campaigns');
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
                ->limit(self::sectionSize('featured', 8))
                ->get();

            // Until vendors buy Featured-tier placements, fall back to the
            // newest approved products so the hero carousel and deals strips
            // never render empty.
            if ($featured->isEmpty()) {
                $featured = Product::query()
                    ->approved()
                    ->with(['images', 'category:id,slug', 'vendor:id,business_name'])
                    ->latest('approved_at')
                    ->limit(self::sectionSize('featured', 8))
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
                    ->limit(self::sectionSize('newest', 12))
                    ->get()
            );
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function campaignProducts(): array
    {
        return Cache::remember('home.campaigns', self::CACHE_TTL_SECONDS, function () {
            $products = Product::query()
                ->approved()
                ->whereHas('campaigns', fn ($query) => $query->live())
                ->with(['images', 'category:id,slug', 'vendor:id,business_name', 'campaigns' => fn ($query) => $query->live()])
                ->limit(self::sectionSize('campaigns', 8))
                ->get();

            return $this->presentProducts($products);
        });
    }

    /**
     * Real trending search terms from what shoppers have actually typed
     * into the catalog search box (CatalogController records one row per
     * normalized term on every non-empty search). Windowed to 30 days so a
     * term that spiked once does not sit at the top forever.
     *
     * @return list<string>
     */
    public function trendingSearches(): array
    {
        return Cache::remember('home.trending_searches', self::CACHE_TTL_SECONDS, function () {
            return SearchTerm::query()
                ->where('last_searched_at', '>=', now()->subDays(30))
                ->orderByDesc('search_count')
                ->limit(self::sectionSize('trending_searches', 10))
                ->pluck('term')
                ->all();
        });
    }

    /**
     * How many orders were actually placed in the last hour, platform-wide.
     *
     * An Order row only ever exists after a webhook-verified payment
     * (CartCheckoutService::completePaidSession) — there is no "pending" or
     * "abandoned" order to accidentally count, so this is a straight count
     * of real, paid orders. Cached briefly so the home page is not counting
     * this on every single anonymous request.
     */
    public function recentOrderCount(): int
    {
        return Cache::remember('home.recent_order_count', 60, function () {
            return Order::query()->where('created_at', '>=', now()->subHour())->count();
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function trendingProducts(): array
    {
        return Cache::remember('home.trending', self::CACHE_TTL_SECONDS, function () {
            $products = Product::query()
                ->approved()
                ->joinSub(
                    ProductViewCount::query()
                        ->where('viewed_on', '>=', now()->subDays(7)->toDateString())
                        ->select('product_id')
                        ->selectRaw('SUM(view_count) as recent_views')
                        ->groupBy('product_id'),
                    'recent_product_views',
                    'recent_product_views.product_id',
                    '=',
                    'products.id',
                )
                ->with(['images', 'category:id,slug', 'vendor:id,business_name'])
                ->orderByDesc('recent_product_views.recent_views')
                ->limit(self::sectionSize('trending', 8))
                ->get();

            return $this->presentProducts($products);
        });
    }

    /**
     * Hero carousel slides, admin-authored (Admin/Merchandising/HeroSlides).
     * offer_value is intentionally not resolved here for 'from_price'/
     * 'campaign_discount' rows — the frontend computes those from the real
     * featuredProducts/campaignProducts payloads already on the page, so a
     * slide's claimed discount can never drift from what is actually live.
     *
     * @return array<int, array<string, mixed>>
     */
    public function heroSlides(): array
    {
        return Cache::remember('home.hero_slides', self::CACHE_TTL_SECONDS, function () {
            return HeroSlide::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (HeroSlide $slide) => [
                    'eyebrow' => $slide->eyebrow,
                    'title' => $slide->title,
                    'description' => $slide->description,
                    'ctaLabel' => $slide->cta_label,
                    'ctaTarget' => $slide->cta_target,
                    'theme' => $slide->theme,
                    'emoji' => $slide->emoji,
                    'offerType' => $slide->offer_type,
                    'offerLabel' => $slide->offer_label,
                    'offerValue' => $slide->offer_value,
                ])
                ->values()
                ->all();
        });
    }

    public function recordView(Product $product): void
    {
        ProductViewCount::query()->updateOrCreate(
            ['product_id' => $product->id, 'viewed_on' => now()->toDateString()],
            [],
        );
        ProductViewCount::query()
            ->where('product_id', $product->id)
            ->whereDate('viewed_on', now()->toDateString())
            ->increment('view_count');
        Cache::forget('home.trending');
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function presentProducts($products): array
    {
        return $products->map(function (Product $product) {
            // Mirrors CatalogController::effectivePrice() so a product shows
            // the same price everywhere: the cheapest live campaign price
            // when one exists (and it beats the sticker price), the
            // vendor's price otherwise. Only 'campaigns' relation callers
            // (campaignProducts()) load the relation, so this is a no-op
            // for every other section.
            $cheapestCampaign = $product->relationLoaded('campaigns')
                ? $product->campaigns->sortBy(fn ($campaign) => (int) $campaign->pivot->sale_price_kobo)->first()
                : null;
            $priceKobo = $cheapestCampaign !== null
                ? min($product->price_kobo, (int) $cheapestCampaign->pivot->sale_price_kobo)
                : $product->price_kobo;
            $onDeal = $priceKobo < $product->price_kobo;

            return [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'slug' => $product->slug,
                'priceKobo' => $priceKobo,
                'compareAtPriceKobo' => $product->compare_at_price_kobo
                    ?? ($onDeal ? $product->price_kobo : null),
                'ratingAverage' => $product->rating_average !== null ? (float) $product->rating_average : null,
                'ratingCount' => $product->rating_count,
                'imageUrl' => $product->primaryImageUrl(),
                'imageUrls' => $product->images->map(fn ($image) => $image->url())->values()->all(),
                'categorySlug' => $product->category->slug,
                'description' => Str::limit($product->description, 600),
                'stockQuantity' => $product->stock_quantity,
                'vendorName' => $product->vendor->business_name,
                'campaignEndsAt' => $onDeal ? $cheapestCampaign?->ends_at?->toIso8601String() : null,
            ];
        })->all();
    }
}
