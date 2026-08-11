<?php

namespace App\Modules\Vendor\Services;

use App\Models\Setting;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\VendorPreparationEvent;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Models\VendorRating;
use App\Modules\Vendor\Models\VendorRatingSnapshot;
use App\Modules\Vendor\Models\VendorRatingTier;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ReturnStatus;
use App\Shared\Enums\VendorPreparationStatus;

/**
 * Works out how a vendor is performing, and which tier that earns.
 *
 * Reproducible by construction, which Phase 2D asks for explicitly: the score
 * is a pure function of rows already in the database — delivered orders,
 * rejections, completed returns, late preparations, product ratings — with no
 * running tally anywhere. Recalculating twice on unchanged data gives the same
 * answer, and a threshold set wrongly is fixed by fixing the threshold rather
 * than by unpicking an accumulated total.
 *
 * The weightings are settings, not constants. What a marketplace considers
 * important changes, and it should not take a deploy to say so.
 */
class VendorRatingService
{
    /** Weight defaults, all overridable from the admin screen. */
    private const DEFAULTS = [
        'vendor_rating.weight_fulfilment' => 40,
        'vendor_rating.weight_rejection' => 25,
        'vendor_rating.weight_returns' => 15,
        'vendor_rating.weight_punctuality' => 10,
        'vendor_rating.weight_reviews' => 10,
        // Below this many delivered orders a vendor is simply "new" — judging
        // someone on two orders is noise, not a rating.
        'vendor_rating.minimum_orders_to_rate' => 5,
    ];

    /**
     * The measured facts behind a vendor's rating.
     *
     * @return array<string, mixed>
     */
    public function metricsFor(VendorProfile $vendor): array
    {
        $counts = Order::query()
            ->where('vendor_id', $vendor->id)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as delivered', [OrderStatus::Delivered->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as rejected', [OrderStatus::VendorRejected->value])
            ->first();

        /*
         * Lateness is read from the SLA breaches the scheduler already
         * records, not recomputed from `prepare_due_at`.
         *
         * Deriving it live would give a different answer every day — an order
         * late last March stops looking late once it ships — and Phase 2D
         * requires the rating be reproducible. The breach event is a fact with
         * a date on it, so it counts the same tomorrow as it does today.
         */
        $late = VendorPreparationEvent::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', VendorPreparationStatus::SlaBreached)
            ->count();

        $delivered = (int) ($counts->delivered ?? 0);
        $rejected = (int) ($counts->rejected ?? 0);
        $total = (int) ($counts->total ?? 0);

        $returned = ReturnRequest::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', ReturnStatus::Refunded)
            ->count();

        $averageRating = Product::query()
            ->where('vendor_id', $vendor->id)
            ->whereNotNull('rating_average')
            ->where('rating_count', '>', 0)
            ->avg('rating_average');

        return [
            'total_orders' => $total,
            'delivered_orders' => $delivered,
            'rejected_orders' => $rejected,
            'returned_orders' => $returned,
            'late_preparations' => $late,
            'average_product_rating' => $averageRating === null ? null : round((float) $averageRating, 2),
            'rejection_percent' => $total > 0 ? round($rejected / $total * 100, 2) : 0.0,
            'return_percent' => $delivered > 0 ? round($returned / $delivered * 100, 2) : 0.0,
        ];
    }

    /**
     * Score out of 100 from those facts.
     *
     * Each component is a proportion scaled by its weight, so the total is
     * bounded and every part of it can be explained to a vendor in one
     * sentence. A vendor with too few orders to judge scores the neutral
     * midpoint rather than zero — a new seller is unproven, not bad.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function scoreFrom(array $metrics): int
    {
        $weights = Setting::many(self::DEFAULTS);
        $minimumOrders = (int) $weights['vendor_rating.minimum_orders_to_rate'];

        if ((int) $metrics['total_orders'] < $minimumOrders) {
            return 50;
        }

        $total = max(1, (int) $metrics['total_orders']);
        $delivered = (int) $metrics['delivered_orders'];

        // Getting orders delivered at all.
        $fulfilment = $delivered / $total;
        // Not rejecting them.
        $rejection = 1 - ((int) $metrics['rejected_orders'] / $total);
        // Not having them sent back.
        $returns = $delivered > 0 ? 1 - min(1, (int) $metrics['returned_orders'] / $delivered) : 1;
        // Packing on time.
        $punctuality = 1 - min(1, (int) $metrics['late_preparations'] / $total);
        // What customers said. No reviews scores neutral rather than zero.
        $reviews = $metrics['average_product_rating'] === null
            ? 0.5
            : min(1, (float) $metrics['average_product_rating'] / 5);

        $score = $fulfilment * (int) $weights['vendor_rating.weight_fulfilment']
            + $rejection * (int) $weights['vendor_rating.weight_rejection']
            + $returns * (int) $weights['vendor_rating.weight_returns']
            + $punctuality * (int) $weights['vendor_rating.weight_punctuality']
            + $reviews * (int) $weights['vendor_rating.weight_reviews'];

        return (int) max(0, min(100, round($score)));
    }

    /**
     * The best tier this vendor qualifies for, or null if none.
     *
     * Highest first, so a vendor lands in the best band whose every condition
     * they meet.
     */
    public function tierFor(int $score, array $metrics): ?VendorRatingTier
    {
        return VendorRatingTier::query()
            ->where('status', true)
            ->orderByDesc('minimum_score')
            ->orderByDesc('sort_order')
            ->get()
            ->first(fn (VendorRatingTier $tier) => $tier->isMetBy(
                $score,
                (int) $metrics['delivered_orders'],
                (float) $metrics['rejection_percent'],
                (float) $metrics['return_percent'],
            ));
    }

    /**
     * Recalculate and store one vendor's standing.
     *
     * A snapshot is written only when the tier actually changes, so the
     * history reads as a list of moves rather than one row per nightly run.
     */
    public function recalculate(VendorProfile $vendor): VendorRating
    {
        $metrics = $this->metricsFor($vendor);
        $score = $this->scoreFrom($metrics);
        $tier = $this->tierFor($score, $metrics);

        $rating = VendorRating::query()->firstOrNew(['vendor_id' => $vendor->id]);
        $previousTierId = $rating->vendor_rating_tier_id;

        $rating->fill([
            'vendor_rating_tier_id' => $tier?->id,
            'score' => $score,
            'delivered_orders' => $metrics['delivered_orders'],
            'rejected_orders' => $metrics['rejected_orders'],
            'returned_orders' => $metrics['returned_orders'],
            'late_preparations' => $metrics['late_preparations'],
            'average_product_rating' => $metrics['average_product_rating'],
            'calculated_at' => now(),
        ])->save();

        if ($previousTierId !== $tier?->id) {
            VendorRatingSnapshot::query()->create([
                'vendor_id' => $vendor->id,
                'vendor_rating_tier_id' => $tier?->id,
                'score' => $score,
                'metrics' => $metrics,
                'captured_at' => now(),
            ]);
        }

        return $rating;
    }
}
