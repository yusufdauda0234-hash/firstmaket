<?php

namespace App\Modules\Affiliates\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliateConversion extends Model
{
    public const TYPE_SIGNUP = 'signup';

    public const TYPE_VERIFIED_USER = 'verified_user';

    public const TYPE_DELIVERED_ORDER = 'delivered_order';

    public const TYPE_COMPLETED_PLAN_ORDER = 'completed_plan_order';

    public const TYPE_VENDOR_PRODUCT = 'vendor_product';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_REVIEW = 'review';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['affiliate_id', 'affiliate_attribution_id', 'user_id', 'order_id', 'conversion_type', 'status', 'order_value_kobo', 'qualified_at'];

    protected function casts(): array { return ['order_value_kobo' => 'integer', 'qualified_at' => 'datetime']; }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo { return $this->belongsTo(Affiliate::class); }

    /** @return BelongsTo<AffiliateAttribution, $this> */
    public function attribution(): BelongsTo { return $this->belongsTo(AffiliateAttribution::class, 'affiliate_attribution_id'); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** @return HasMany<AffiliateFraudFlag, $this> */
    public function fraudFlags(): HasMany { return $this->hasMany(AffiliateFraudFlag::class, 'conversion_id'); }

    /** @return HasMany<AffiliateCommission, $this> */
    public function commissions(): HasMany { return $this->hasMany(AffiliateCommission::class, 'conversion_id'); }
}
