<?php

namespace App\Modules\Affiliates\Services;

use App\Models\Setting;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Models\AffiliateTier;

/**
 * What a partner earns, and why.
 *
 * Tiers are earned rather than granted: the resolver looks at what an
 * affiliate has actually delivered and picks the highest tier they meet.
 * That keeps "why am I on this rate" answerable from their own dashboard,
 * and stops rate-setting turning into a negotiation with whoever is on
 * support that day.
 *
 * With no tiers configured at all this falls back to the flat
 * `affiliates.commission_percent` setting the scheme launched with, so an
 * install that never touches the tier screen keeps working unchanged.
 */
class AffiliateTierResolver
{
    /**
     * The tier an affiliate qualifies for right now, ignoring what is stored
     * on them.
     *
     * `$excludingConversionId` exists because a conversion row is written
     * before it is priced. Without it, a sale would count towards the tier
     * that prices it — one order could promote the partner and then pay
     * itself at the new, higher rate. The rule is instead the one a partner
     * would assume: your rate comes from your track record *before* this
     * sale, and the better rate applies from the next one.
     */
    public function resolveFor(Affiliate $affiliate, ?int $excludingConversionId = null): ?AffiliateTier
    {
        $delivered = $affiliate->conversions()
            ->where('status', AffiliateConversion::STATUS_QUALIFIED)
            ->whereIn('conversion_type', [
                AffiliateConversion::TYPE_DELIVERED_ORDER,
                AffiliateConversion::TYPE_COMPLETED_PLAN_ORDER,
            ])
            ->when($excludingConversionId !== null, fn ($query) => $query->whereKeyNot($excludingConversionId));

        $count = (clone $delivered)->count();
        $value = (int) (clone $delivered)->sum('order_value_kobo');

        return AffiliateTier::query()
            ->where('is_active', true)
            ->where('min_delivered_conversions', '<=', $count)
            ->where('min_delivered_value_kobo', '<=', $value)
            // Best qualifying tier, by the thresholds it demands — the tier
            // that asks the most of a partner is the one that pays the most.
            ->orderByDesc('min_delivered_value_kobo')
            ->orderByDesc('min_delivered_conversions')
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * The same answer as {@see effectiveTier()}, for a whole list, in a fixed
     * number of queries.
     *
     * The single-affiliate version costs three queries — a count, a sum and a
     * tier lookup — which is nothing on a dashboard and quietly awful on the
     * admin list, where it runs once per row. This does the counting in one
     * grouped query and resolves the ladder in PHP.
     *
     * @param  \Illuminate\Support\Collection<int, Affiliate>  $affiliates
     * @return array<int, AffiliateTier|null> Keyed by affiliate id.
     */
    public function effectiveTiersFor($affiliates): array
    {
        if ($affiliates->isEmpty()) {
            return [];
        }

        $totals = AffiliateConversion::query()
            ->selectRaw('affiliate_id, COUNT(*) as delivered_count, COALESCE(SUM(order_value_kobo), 0) as delivered_value')
            ->whereIn('affiliate_id', $affiliates->pluck('id'))
            ->where('status', AffiliateConversion::STATUS_QUALIFIED)
            ->whereIn('conversion_type', [
                AffiliateConversion::TYPE_DELIVERED_ORDER,
                AffiliateConversion::TYPE_COMPLETED_PLAN_ORDER,
            ])
            ->groupBy('affiliate_id')
            ->get()
            ->keyBy('affiliate_id');

        // Best tier first, so the first one an affiliate qualifies for wins —
        // the same ordering the per-affiliate query uses.
        $ladder = AffiliateTier::query()
            ->where('is_active', true)
            ->orderByDesc('min_delivered_value_kobo')
            ->orderByDesc('min_delivered_conversions')
            ->orderBy('sort_order')
            ->get();

        $default = $ladder->firstWhere('is_default', true) ?? $ladder->first();

        $resolved = [];

        foreach ($affiliates as $affiliate) {
            $row = $totals->get($affiliate->id);
            $count = (int) ($row->delivered_count ?? 0);
            $value = (int) ($row->delivered_value ?? 0);

            $resolved[$affiliate->id] = $ladder->first(
                fn (AffiliateTier $tier) => $tier->min_delivered_conversions <= $count
                    && $tier->min_delivered_value_kobo <= $value,
            ) ?? $affiliate->tier ?? $default;
        }

        return $resolved;
    }

    public function defaultTier(): ?AffiliateTier
    {
        return AffiliateTier::query()->where('is_active', true)->where('is_default', true)->first()
            ?? AffiliateTier::query()->where('is_active', true)->orderBy('sort_order')->first();
    }

    /**
     * The tier actually applied to an affiliate: whichever they have earned,
     * falling back to the one recorded on the account, then the default.
     */
    public function effectiveTier(Affiliate $affiliate, ?int $excludingConversionId = null): ?AffiliateTier
    {
        return $this->resolveFor($affiliate, $excludingConversionId) ?? $affiliate->tier ?? $this->defaultTier();
    }

    /** What one qualified conversion is worth, in kobo. */
    public function commissionFor(Affiliate $affiliate, AffiliateConversion $conversion): int
    {
        $tier = $this->effectiveTier($affiliate, $conversion->id);

        // Signups and verifications are tracked as conversions so partners can
        // see the funnel, but they are not paid: nobody has bought anything
        // yet, and paying per signup is what makes a scheme worth gaming.
        if (in_array($conversion->conversion_type, [
            AffiliateConversion::TYPE_SIGNUP,
            AffiliateConversion::TYPE_VERIFIED_USER,
        ], true)) {
            return 0;
        }

        if ($conversion->conversion_type === AffiliateConversion::TYPE_VENDOR_PRODUCT) {
            // A recruited seller is worth a fixed finder's fee where a tier
            // sets one; otherwise it falls back to the percentage rule so the
            // behaviour before tiers existed is preserved.
            $flat = (int) ($tier?->vendor_recruitment_kobo ?? 0);

            return $flat > 0 ? $flat : $this->fallbackPercentage($conversion->order_value_kobo);
        }

        return $tier !== null
            ? $tier->commissionForOrder($conversion->order_value_kobo)
            : $this->fallbackPercentage($conversion->order_value_kobo);
    }

    private function fallbackPercentage(int $valueKobo): int
    {
        $percent = (float) Setting::get(
            'affiliates.commission_percent',
            AffiliateService::DEFAULT_COMMISSION_PERCENT,
        );

        return (int) floor($valueKobo * $percent / 100);
    }
}
