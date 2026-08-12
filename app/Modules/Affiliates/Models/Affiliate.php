<?php

namespace App\Modules\Affiliates\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Affiliate extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id', 'display_name', 'status', 'tier_id', 'rank_entered_at', 'rank_baseline_conversion_id',
        'approved_by', 'approved_at', 'suspended_at', 'suspension_reason', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'rank_entered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    /** @return BelongsTo<AffiliateTier, $this> */
    public function tier(): BelongsTo { return $this->belongsTo(AffiliateTier::class, 'tier_id'); }

    /** @return HasMany<AffiliateLink, $this> */
    public function links(): HasMany { return $this->hasMany(AffiliateLink::class); }

    /** @return HasMany<AffiliateConversion, $this> */
    public function conversions(): HasMany { return $this->hasMany(AffiliateConversion::class); }

    /** @return HasMany<AffiliateCommission, $this> */
    public function commissions(): HasMany { return $this->hasMany(AffiliateCommission::class); }

    /** @return HasMany<AffiliateBankAccount, $this> */
    public function bankAccounts(): HasMany { return $this->hasMany(AffiliateBankAccount::class); }

    /** @return HasMany<AffiliatePayoutItem, $this> */
    public function payoutItems(): HasMany { return $this->hasMany(AffiliatePayoutItem::class); }

    /** @return HasMany<AffiliateFraudFlag, $this> */
    public function fraudFlags(): HasMany { return $this->hasMany(AffiliateFraudFlag::class); }

    /** @return HasMany<AffiliateUpgradeRequest, $this> */
    public function upgradeRequests(): HasMany { return $this->hasMany(AffiliateUpgradeRequest::class); }

    /**
     * Trading status. A suspended partner keeps their history and their
     * already-earned commissions, but earns nothing new and is paid nothing
     * further until the suspension is lifted.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->suspended_at === null;
    }
}
