<?php

namespace App\Modules\Affiliates\Models;

use App\Models\User;
use App\Shared\Enums\PayoutBatchStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly run of affiliate payouts, held for Finance approval.
 *
 * Deliberately a separate table from vendor payouts even though the shape is
 * similar: partner money and vendor settlement money are reconciled by
 * different people against different ledgers, and merging them would make
 * "what do we owe partners this month" a query with a filter on it rather
 * than a table.
 */
class AffiliatePayoutBatch extends Model
{
    use HasUuid;

    protected $fillable = [
        'period_start', 'period_end', 'status', 'total_amount_kobo',
        'minimum_threshold_kobo', 'generated_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => PayoutBatchStatus::class,
            'total_amount_kobo' => 'integer',
            'minimum_threshold_kobo' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return HasMany<AffiliatePayoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AffiliatePayoutItem::class, 'batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
