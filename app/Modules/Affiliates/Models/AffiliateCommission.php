<?php

namespace App\Modules\Affiliates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommission extends Model
{
    /** Earned, not yet gathered into a payout batch. */
    public const STATUS_PENDING = 'pending';

    /** Sitting in a batch awaiting Finance. */
    public const STATUS_BATCHED = 'batched';

    public const STATUS_PAID = 'paid';

    /** The conversion behind it was rejected — never payable. */
    public const STATUS_VOID = 'void';

    protected $fillable = ['affiliate_id', 'conversion_id', 'amount_kobo', 'status', 'payout_item_id'];

    protected function casts(): array { return ['amount_kobo' => 'integer']; }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo { return $this->belongsTo(Affiliate::class); }

    /** @return BelongsTo<AffiliateConversion, $this> */
    public function conversion(): BelongsTo { return $this->belongsTo(AffiliateConversion::class, 'conversion_id'); }

    /** @return BelongsTo<AffiliatePayoutItem, $this> */
    public function payoutItem(): BelongsTo { return $this->belongsTo(AffiliatePayoutItem::class, 'payout_item_id'); }
}
