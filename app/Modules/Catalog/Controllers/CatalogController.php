<?php

namespace App\Modules\Catalog\Controllers;

use App\Modules\Cart\Services\CartService;
use App\Modules\Cart\Services\CartSummary;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\HomeDataService;
use App\Modules\Catalog\Services\ProductAttributeService;
use App\Modules\Catalog\Support\VideoLink;
use App\Shared\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public catalog (docs/FirstMaket_Implementation_Plan.md Sprint 3):
 * approved products only, with search, category and price filters, and
 * sorting. No authentication required.
 */
class CatalogController
{
    public function index(Request $request): Response
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        $activeCategory = $categories->firstWhere('slug', (string) $request->query('category'));
        $query = trim((string) $request->query('query'));
        $sort = (string) $request->query('sort', 'newest');
        $minPrice = $request->query('min_price');
        $maxPrice = $request->query('max_price');

        $products = Product::query()
            ->approved()
            ->with(['images', 'category:id,slug'])
            // Whole branch, not just the exact category: browsing
            // "Electronics" must still show the phones filed beneath it.
            ->when($activeCategory !== null, fn ($q) => $q->whereIn(
                'category_id',
                $activeCategory->selfAndDescendantIds(),
            ))
            // LIKE is case-insensitive under MySQL/MariaDB's utf8mb4_*_ci
            // collations, so no ILIKE equivalent is needed.
            ->when($query !== '', fn ($q) => $q->where(function ($inner) use ($query) {
                $inner->where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            }))
            ->when(is_numeric($minPrice), fn ($q) => $q->where('price_kobo', '>=', (int) ((float) $minPrice * 100)))
            ->when(is_numeric($maxPrice), fn ($q) => $q->where('price_kobo', '<=', (int) ((float) $maxPrice * 100)))
            ->when($sort === 'price_asc', fn ($q) => $q->orderBy('price_kobo'))
            ->when($sort === 'price_desc', fn ($q) => $q->orderByDesc('price_kobo'))
            ->when(! in_array($sort, ['price_asc', 'price_desc'], true), fn ($q) => $q->latest('approved_at'))
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'slug' => $product->slug,
                'priceKobo' => $product->price_kobo,
                'compareAtPriceKobo' => $product->compare_at_price_kobo,
                'ratingAverage' => $product->rating_average !== null ? (float) $product->rating_average : null,
                'ratingCount' => $product->rating_count,
                'stockQuantity' => $product->stock_quantity,
                'imageUrl' => $product->primaryImageUrl(),
                'categorySlug' => $product->category->slug,
            ]);

        return Inertia::render('Public/Catalog', [
            'products' => $products,
            /*
             * The same nested shape the header menu is given everywhere else.
             *
             * This used to be every active category in one flat list, which
             * also overrode the shared prop the header reads — so on this page
             * the menu listed "Televisions" and "Smartphones" as peers of
             * "Electronics", and had no children to drill into.
             */
            'categories' => app(HomeDataService::class)->categories(),
            'filters' => [
                'query' => $query,
                'category' => $activeCategory !== null ? $activeCategory->slug : '',
                'sort' => $sort,
                'minPrice' => is_numeric($minPrice) ? (float) $minPrice : null,
                'maxPrice' => is_numeric($maxPrice) ? (float) $maxPrice : null,
            ],
        ]);
    }

    /**
     * Live search suggestions for the header search box: approved product
     * names only, so nothing unpublished can leak through autocomplete.
     */
    public function suggest(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query'));

        if (mb_strlen($query) < 2) {
            return response()->json(['suggestions' => []]);
        }

        $suggestions = Product::query()
            ->approved()
            ->where('name', 'like', "%{$query}%")
            ->orderByDesc('approved_at')
            ->limit(8)
            ->get(['name', 'slug'])
            ->map(fn (Product $product) => ['name' => $product->name, 'slug' => $product->slug]);

        return response()->json(['suggestions' => $suggestions]);
    }

    /**
     * Products for the header categories mega menu: the newest approved
     * listings, optionally scoped to one category, so the menu can show
     * real merchandise next to the category tiles.
     */
    public function menuProducts(Request $request): JsonResponse
    {
        $categorySlug = trim((string) $request->query('category'));

        /*
         * The whole branch, not the one category.
         *
         * Listings sit on the most specific category there is — a camera is
         * filed under Cameras, never under Electronics — so matching the slug
         * exactly meant hovering a parent in the menu returned nothing at all.
         * This mirrors what index() already does for the catalogue page.
         */
        $category = $categorySlug === ''
            ? null
            : Category::query()->where('slug', $categorySlug)->first();

        $products = Product::query()
            ->approved()
            ->with(['images', 'category:id,slug'])
            ->when(
                $category !== null,
                fn ($q) => $q->whereIn('category_id', $category->selfAndDescendantIds()),
            )
            // A slug that matches no category must not silently fall back to
            // "everything" — that would show unrelated products under a
            // heading claiming otherwise.
            ->when($categorySlug !== '' && $category === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest('approved_at')
            ->limit(8)
            ->get()
            ->map(fn (Product $product) => [
                'name' => $product->name,
                'slug' => $product->slug,
                'priceKobo' => $product->price_kobo,
                'compareAtPriceKobo' => $product->compare_at_price_kobo,
                'imageUrl' => $product->primaryImageUrl(),
            ]);

        return response()->json(['products' => $products]);
    }

    public function show(Request $request, Product $product, CartService $cartService): Response
    {
        // Guests may only ever open Approved listings.
        abort_unless($product->status === ProductStatus::Approved, 404);

        $product->load(['images', 'category:id,name,slug', 'vendor:id,business_name']);

        $related = $this->productStrip(
            Product::query()
                ->approved()
                ->where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)
                ->latest('approved_at')
                ->limit(6),
        );

        // "More to love" deliberately reaches outside the category — the
        // strip above already covers more of the same.
        $moreToLove = $this->productStrip(
            Product::query()
                ->approved()
                ->where('category_id', '!=', $product->category_id)
                ->where('id', '!=', $product->id)
                ->inRandomOrder()
                ->limit(12),
        );

        // Buy-box cart summary, so a shopper can see what they have already
        // collected without leaving the page.
        $cartLines = $cartService->lines($request->user());
        $cartSummary = CartSummary::fromLines($cartLines);

        return Inertia::render('Public/ProductShow', [
            'product' => [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'priceKobo' => $product->price_kobo,
                'compareAtPriceKobo' => $product->compare_at_price_kobo,
                'ratingAverage' => $product->rating_average !== null ? (float) $product->rating_average : null,
                'ratingCount' => $product->rating_count,
                'stockQuantity' => $product->stock_quantity,
                'vendorName' => $product->vendor->business_name,
                'category' => ['name' => $product->category->name, 'slug' => $product->category->slug],
                'images' => $product->images->map(fn ($image) => ['id' => $image->id, 'url' => $image->url()]),
                // Rebuilt from the video id, never the string the vendor
                // typed — the page puts this straight into an iframe. Null
                // when there is no link, or when an old row predates the
                // providers currently supported.
                'video' => VideoLink::parse($product->video_url)?->toArray(),
                // Whatever the vendor filled in for this category's
                // admin-defined fields. Empty until staff define some.
                'specifications' => app(ProductAttributeService::class)->specificationsFor($product),
            ],
            'relatedProducts' => $related,
            'moreToLove' => $moreToLove,
            // Feeds the slide-out cart drawer on this page.
            'cart' => [
                'itemCount' => $cartSummary->itemCount,
                'subtotalKobo' => $cartSummary->subtotalKobo,
                'shippingKobo' => $cartSummary->shippingKobo,
                'totalKobo' => $cartSummary->totalKobo,
                'quantityOfThisProduct' => $cartService->quantityOf($request->user(), $product),
                'lines' => $cartLines->map(fn (array $line) => [
                    'productUuid' => $line['product']->uuid,
                    'productName' => $line['product']->name,
                    'productSlug' => $line['product']->slug,
                    'productImage' => $line['product']->primaryImageUrl(),
                    'quantity' => $line['quantity'],
                    'lineTotalKobo' => $line['product']->price_kobo * $line['quantity'],
                ])->values(),
            ],
            'freeShippingThresholdKobo' => (int) config('firstmaket.shipping.free_threshold_kobo'),
            // Nested, matching every other page — the header menu reads this.
            'categories' => app(HomeDataService::class)->categories(),
        ]);
    }

    /**
     * The ProductSummary shape the storefront cards and carousels consume.
     * Takes an unexecuted query so callers own the filtering and ordering.
     *
     * @param  Builder<Product>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function productStrip(Builder $query): Collection
    {
        return $query
            ->with(['images', 'category:id,slug'])
            ->get()
            ->map(fn (Product $item) => [
                'uuid' => $item->uuid,
                'name' => $item->name,
                'slug' => $item->slug,
                'priceKobo' => $item->price_kobo,
                'compareAtPriceKobo' => $item->compare_at_price_kobo,
                'ratingAverage' => $item->rating_average !== null ? (float) $item->rating_average : null,
                'ratingCount' => $item->rating_count,
                'stockQuantity' => $item->stock_quantity,
                'imageUrl' => $item->primaryImageUrl(),
                'categorySlug' => $item->category->slug,
            ]);
    }
}
