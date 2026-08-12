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
     * The highest rank an affiliate's record would *qualify* them to apply
     * for — not the rank they are on.
     *
     * Used to tell a partner "you have done enough to apply for Growth", and
     * to let staff see at a glance who is ready. Granting the rank is a
     * separate, reviewed step in AffiliateRankService.
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
     * The same answer as {@see effectiveTier()}, for a whole list, without a
     * query per row.
     *
     * @param  \Illuminate\Support\Collection<int, Affiliate>  $affiliates
     * @return array<int, AffiliateTier|null> Keyed by affiliate id.
     */
    public function effectiveTiersFor($affiliates): array
    {
        if ($affiliates->isEmpty()) {
            return [];
        }

        $ranks = AffiliateTier::query()
            ->whereIn('id', $affiliates->pluck('tier_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $default = $this->defaultTier();

        return $affiliates
            ->mapWithKeys(fn (Affiliate $affiliate) => [
                $affiliate->id => $ranks->get($affiliate->tier_id) ?? $default,
            ])
            ->all();
    }

    public function defaultTier(): ?AffiliateTier
    {
        return AffiliateTier::query()->where('is_active', true)->where('is_default', true)->first()
            ?? AffiliateTier::query()->where('is_active', true)->orderBy('sort_order')->first();
    }

    /**
     * The rank actually applied to an affiliate — the one recorded on their
     * account, falling back to the entry rank.
     *
     * Note what this no longer does: it does not promote anybody. Ranks used
     * to be earned silently the moment a threshold was crossed, which meant
     * nobody ever looked at a partner before widening what they could do.
     * Since a rank now carries a referral quota and a link lifetime as well as
     * a rate, it is granted by review instead — {@see resolveFor()} says which
     * rank somebody *qualifies to apply for*, and AffiliateRankService owns
     * the application.
     */
    public function effectiveTier(Affiliate $affiliate, ?int $excludingConversionId = null): ?AffiliateTier
    {
        return $affiliate->tier ?? $this->defaultTier();
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
