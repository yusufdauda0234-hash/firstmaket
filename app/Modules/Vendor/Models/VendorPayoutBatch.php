<?php

namespace App\Modules\Vendor\Models;

use App\Models\User;
use App\Shared\Enums\PayoutBatchStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A periodic (weekly) batch of cleared vendor earnings reviewed by Finance
 * (docs/firstmarket-Database_Schema.md section 9).
 *
 * @property int $id
 * @property string $uuid
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property PayoutBatchStatus $status
 * @property int $total_amount_kobo
 * @property int|null $generated_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, VendorPayoutItem> $items
 * @property-read User|null $generatedBy
 * @property-read User|null $approvedBy
 */
class VendorPayoutBatch extends Model
{
    use HasUuid;

    protected $fillable = [
        'period_start',
        'period_end',
        'status',
        'total_amount_kobo',
        'generated_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => PayoutBatchStatus::class,
            'total_amount_kobo' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return HasMany<VendorPayoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VendorPayoutItem::class, 'batch_id');
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
