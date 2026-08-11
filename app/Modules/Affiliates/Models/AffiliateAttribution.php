<?php

namespace App\Modules\Affiliates\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record that a customer arrived through a partner's link.
 *
 * One per user, ever — the first valid attribution wins and cannot be
 * overwritten, so a partner cannot hijack a customer somebody else brought
 * by getting them to click a second link later.
 */
class AffiliateAttribution extends Model
{
    protected $fillable = ['affiliate_link_id', 'user_id', 'token', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<AffiliateLink, $this> */
    public function link(): BelongsTo { return $this->belongsTo(AffiliateLink::class, 'affiliate_link_id'); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /**
     * Whether this attribution still earns.
     *
     * A null expiry is an attribution recorded before windows existed; those
     * stay open rather than being retroactively closed, because the partner
     * earned them under the old rule.
     */
    public function isWithinWindow(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
