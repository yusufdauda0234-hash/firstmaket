<?php

namespace App\Modules\Affiliates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named commission rule set. Which tier an affiliate earns on is worked
 * out from what they have actually delivered (AffiliateTierResolver), not
 * assigned by hand — a partner who hits the numbers gets the rate without
 * having to ask for it.
 */
class AffiliateTier extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_FLAT = 'flat';

    protected $fillable = [
        'name', 'description', 'commission_type', 'commission_percent', 'flat_amount_kobo',
        'vendor_recruitment_kobo', 'referral_quota', 'link_expiry_days', 'max_active_links',
        'requires_approval', 'min_delivered_conversions', 'min_delivered_value_kobo',
        'is_default', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'flat_amount_kobo' => 'integer',
            'vendor_recruitment_kobo' => 'integer',
            'referral_quota' => 'integer',
            'link_expiry_days' => 'integer',
            'max_active_links' => 'integer',
            'requires_approval' => 'boolean',
            'min_delivered_conversions' => 'integer',
            'min_delivered_value_kobo' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Affiliate, $this> */
    public function affiliates(): HasMany
    {
        return $this->hasMany(Affiliate::class, 'tier_id');
    }

    /** @return HasMany<AffiliateRankRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(AffiliateRankRequirement::class, 'tier_id')->orderBy('sort_order');
    }

    /** Zero means unlimited, which is what the top of the ladder carries. */
    public function hasUnlimitedReferrals(): bool
    {
        return $this->referral_quota <= 0;
    }

    public function linksNeverExpire(): bool
    {
        return $this->link_expiry_days <= 0;
    }

    /** The next rank up the ladder, or null at the top. */
    public function nextRank(): ?self
    {
        return self::query()
            ->where('is_active', true)
            ->where('sort_order', '>', $this->sort_order)
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * What this tier pays for one delivered order of the given value.
     *
     * Floored, never rounded up: paying a fraction of a kobo more than the
     * rule states is money the business did not agree to.
     */
    public function commissionForOrder(int $orderValueKobo): int
    {
        return $this->commission_type === self::TYPE_FLAT
            ? (int) $this->flat_amount_kobo
            : (int) floor($orderValueKobo * (float) $this->commission_percent / 100);
    }
}
