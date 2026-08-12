<?php

namespace App\Modules\Affiliates\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner asking to move up the ladder.
 *
 * Deliberately a request rather than an automatic promotion. A rank above the
 * first one carries a bigger referral quota and a longer link life, which is
 * exactly the leverage somebody running a scam would want — so the documents
 * a rank asks for get looked at by a person before it is granted.
 */
class AffiliateUpgradeRequest extends Model
{
    use HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'affiliate_id', 'from_tier_id', 'to_tier_id', 'status',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo { return $this->belongsTo(Affiliate::class); }

    /** @return BelongsTo<AffiliateTier, $this> */
    public function fromTier(): BelongsTo { return $this->belongsTo(AffiliateTier::class, 'from_tier_id'); }

    /** @return BelongsTo<AffiliateTier, $this> */
    public function toTier(): BelongsTo { return $this->belongsTo(AffiliateTier::class, 'to_tier_id'); }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    /** @return HasMany<AffiliateUpgradeAnswer, $this> */
    public function answers(): HasMany { return $this->hasMany(AffiliateUpgradeAnswer::class, 'request_id'); }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
