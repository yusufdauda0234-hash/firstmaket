<?php

namespace App\Modules\Affiliates\Models;

use App\Shared\Enums\PayoutItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One affiliate's line inside a payout batch. The commissions it covers
 * point back at it, so "which conversions was I paid for" is answerable
 * from the partner's own dashboard rather than only from Finance's.
 */
class AffiliatePayoutItem extends Model
{
    protected $fillable = [
        'batch_id', 'affiliate_id', 'bank_account_id', 'amount_kobo', 'status',
        'rejection_reason', 'failure_reason', 'paystack_transfer_reference', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutItemStatus::class,
            'amount_kobo' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AffiliatePayoutBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayoutBatch::class, 'batch_id');
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return BelongsTo<AffiliateBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(AffiliateBankAccount::class, 'bank_account_id');
    }

    /** @return HasMany<AffiliateCommission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class, 'payout_item_id');
    }
}
