<?php

namespace App\Modules\Customer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\RecommendationService;
use App\Modules\Customer\Models\Wishlist;
use App\Modules\Customer\Models\WishlistPriceAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WishlistController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $alerts = WishlistPriceAlert::query()
            ->where('user_id', $userId)
            ->pluck('threshold_percent', 'product_id');

        $items = Wishlist::query()
            ->where('user_id', $userId)
            ->with(['product.images', 'product.category:id,slug'])
            ->latest()
            ->get()
            ->map(fn (Wishlist $item) => [
                'uuid' => $item->product->uuid,
                'name' => $item->product->name,
                'slug' => $item->product->slug,
                'priceKobo' => $item->product->price_kobo,
                'compareAtPriceKobo' => $item->product->compare_at_price_kobo,
                'ratingAverage' => $item->product->rating_average !== null ? (float) $item->product->rating_average : null,
                'ratingCount' => $item->product->rating_count,
                'imageUrl' => $item->product->primaryImageUrl(),
                'categorySlug' => $item->product->category->slug,
                'priceAlertPercent' => $alerts->get($item->product->id),
            ])
            ->values();

        return Inertia::render('Account/Wishlist', [
            'products' => $items,
            // Phase 2C. Rules over this customer's own behaviour, and each one
            // carries the reason it was suggested — an unexplained
            // recommendation on a savings product reads as a nudge to spend.
            'recommendations' => app(RecommendationService::class)
                ->forUser($request->user(), 4)
                ->map(fn (array $pick) => [
                    'uuid' => $pick['product']->uuid,
                    'name' => $pick['product']->name,
                    'slug' => $pick['product']->slug,
                    'priceKobo' => $pick['product']->price_kobo,
                    'imageUrl' => $pick['product']->primaryImageUrl(),
                    'reason' => $pick['reason'],
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->status->value === 'approved', 404);

        Wishlist::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
        ]);

        return back()->with('success', 'Product saved to your wishlist.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        Wishlist::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->delete();

        WishlistPriceAlert::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->delete();

        return back()->with('success', 'Product removed from your wishlist.');
    }

    public function updatePriceAlert(Request $request, Product $product): RedirectResponse
    {
        abort_unless(Wishlist::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->exists(), 404);

        $validated = $request->validate(['threshold_percent' => ['required', 'integer', 'in:5,10,20']]);

        WishlistPriceAlert::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            ['threshold_percent' => $validated['threshold_percent']],
        );

        return back()->with('success', 'Price-drop alert updated.');
    }
}