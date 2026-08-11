<?php

namespace App\Modules\Catalog\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\Wishlist;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Support\Collection;

/**
 * "You might also like", worked out from what the customer has actually done.
 *
 * Rules, not a model — the same choice as the savings assistant, for the same
 * reasons: it is explainable, it costs nothing to run, and no customer data
 * leaves the platform to produce it.
 *
 * Every suggestion carries the reason it was made, because the phase plan asks
 * for an explain-why and because an unexplained recommendation on a savings
 * product reads as a nudge to spend. "Because you saved for a laptop" is a
 * statement the customer can check; "recommended for you" is not.
 *
 * Signals, in the order they are trusted:
 *   1. Categories the customer is saving towards — the strongest statement of
 *      intent anyone makes on this site.
 *   2. Categories they have wishlisted.
 *   3. Categories they have bought from before.
 */
class RecommendationService
{
    private const DEFAULTS = [
        'recommendations.limit' => 8,
        // Suggestions within this percentage of what they usually spend. A
        // customer saving for a ₦80,000 phone is not helped by a ₦900,000 one.
        'recommendations.price_band_percent' => 60,
    ];

    /** Reason keys, also what feedback is grouped by. */
    public const REASON_SAVING = 'saving_category';

    public const REASON_WISHLIST = 'wishlist_category';

    public const REASON_PURCHASE = 'purchase_category';

    public const REASON_POPULAR = 'popular';

    /**
     * Suggestions for one customer, best first.
     *
     * @return Collection<int, array{product: Product, reasonKey: string, reason: string}>
     */
    public function forUser(?User $user, int $limit = 0): Collection
    {
        $settings = array_map('intval', Setting::many(self::DEFAULTS));
        $limit = $limit > 0 ? $limit : $settings['recommendations.limit'];

        if ($user === null) {
            return $this->popular($limit, collect());
        }

        [$savingCategories, $wishlistCategories, $purchaseCategories] = $this->signals($user);

        // Never suggest something they already have, already saved, or are
        // already saving towards — the fastest way to look like the site is
        // not paying attention.
        $exclude = $this->alreadySeen($user);

        $typicalKobo = $this->typicalSpendKobo($user);
        $band = $settings['recommendations.price_band_percent'];

        $picks = collect();

        foreach ([
            [self::REASON_SAVING, $savingCategories, 'Because you are saving for something in %s'],
            [self::REASON_WISHLIST, $wishlistCategories, 'Because you saved a %s item'],
            [self::REASON_PURCHASE, $purchaseCategories, 'Because you bought from %s before'],
        ] as [$reasonKey, $categories, $template]) {
            if ($picks->count() >= $limit || $categories->isEmpty()) {
                continue;
            }

            $found = $this->fromCategories(
                $categories->keys()->all(),
                $exclude->merge($picks->pluck('product.id')),
                $limit - $picks->count(),
                $typicalKobo,
                $band,
            );

            foreach ($found as $product) {
                $picks->push([
                    'product' => $product,
                    'reasonKey' => $reasonKey,
                    'reason' => sprintf($template, $product->category?->name ?? 'this category'),
                ]);
            }
        }

        // Top up with genuinely popular items rather than returning a short
        // list — but say plainly that is what they are.
        if ($picks->count() < $limit) {
            $picks = $picks->merge(
                $this->popular($limit - $picks->count(), $exclude->merge($picks->pluck('product.id'))),
            );
        }

        return $picks->take($limit)->values();
    }

    /**
     * The categories this customer has shown interest in, weighted by count.
     *
     * @return array{0: Collection<int, int>, 1: Collection<int, int>, 2: Collection<int, int>}
     */
    private function signals(User $user): array
    {
        $saving = SavingsGoal::query()
            ->where('user_id', $user->id)
            ->where('status', SavingsGoalStatus::Saving)
            ->with('items.product:id,category_id')
            ->get()
            ->flatMap(fn (SavingsGoal $goal) => $goal->items->pluck('product.category_id'))
            ->filter()
            ->countBy();

        $wishlist = Wishlist::query()
            ->where('user_id', $user->id)
            ->with('product:id,category_id')
            ->get()
            ->pluck('product.category_id')
            ->filter()
            ->countBy();

        $purchases = Order::query()
            ->where('customer_id', $user->id)
            ->with('product:id,category_id')
            ->get()
            ->pluck('product.category_id')
            ->filter()
            ->countBy();

        return [
            $saving->sortDesc(),
            $wishlist->sortDesc(),
            $purchases->sortDesc(),
        ];
    }

    /** Product ids the customer has already engaged with. */
    private function alreadySeen(User $user): Collection
    {
        $wishlisted = Wishlist::query()->where('user_id', $user->id)->pluck('product_id');
        $ordered = Order::query()->where('customer_id', $user->id)->pluck('product_id');

        $saving = SavingsGoal::query()
            ->where('user_id', $user->id)
            ->with('items:id,savings_goal_id,product_id')
            ->get()
            ->flatMap(fn (SavingsGoal $goal) => $goal->items->pluck('product_id'));

        return $wishlisted->merge($ordered)->merge($saving)->filter()->unique()->values();
    }

    /**
     * Roughly what this customer spends, for keeping suggestions in range.
     *
     * Null when there is nothing to go on, in which case price is not used to
     * filter at all — better a wide list than an empty one.
     */
    private function typicalSpendKobo(User $user): ?int
    {
        $average = Order::query()->where('customer_id', $user->id)->avg('locked_price_kobo')
            ?? SavingsGoal::query()->where('user_id', $user->id)->avg('target_kobo');

        return $average === null ? null : (int) round((float) $average);
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return Collection<int, Product>
     */
    private function fromCategories(
        array $categoryIds,
        Collection $exclude,
        int $limit,
        ?int $typicalKobo,
        int $bandPercent,
    ): Collection {
        return Product::query()
            ->approved()
            ->whereIn('category_id', $categoryIds)
            ->whereNotIn('id', $exclude->all())
            ->where('stock_quantity', '>', 0)
            ->when($typicalKobo !== null, function ($query) use ($typicalKobo, $bandPercent) {
                $spread = (int) round($typicalKobo * $bandPercent / 100);

                return $query->whereBetween('price_kobo', [
                    max(0, $typicalKobo - $spread),
                    $typicalKobo + $spread,
                ]);
            })
            ->with(['images', 'category:id,name,slug', 'vendor:id,business_name'])
            ->orderByDesc('rating_average')
            ->orderByDesc('rating_count')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, array{product: Product, reasonKey: string, reason: string}> */
    private function popular(int $limit, Collection $exclude): Collection
    {
        return Product::query()
            ->approved()
            ->whereNotIn('id', $exclude->all())
            ->where('stock_quantity', '>', 0)
            ->with(['images', 'category:id,name,slug', 'vendor:id,business_name'])
            ->orderByDesc('rating_count')
            ->orderByDesc('rating_average')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => [
                'product' => $product,
                'reasonKey' => self::REASON_POPULAR,
                'reason' => 'Popular with other shoppers',
            ]);
    }
}
