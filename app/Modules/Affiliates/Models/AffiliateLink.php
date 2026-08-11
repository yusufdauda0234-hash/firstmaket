<?php

namespace App\Modules\Affiliates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One trackable link. An affiliate may run several — a link per campaign,
 * so "which post actually worked" is answerable.
 */
class AffiliateLink extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['affiliate_id', 'code', 'signature', 'label', 'campaign', 'status', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo { return $this->belongsTo(Affiliate::class); }

    /** @return HasMany<AffiliateClick, $this> */
    public function clicks(): HasMany { return $this->hasMany(AffiliateClick::class); }

    /** @return HasMany<AffiliateAttribution, $this> */
    public function attributions(): HasMany { return $this->hasMany(AffiliateAttribution::class); }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->hasExpired();
    }
}
