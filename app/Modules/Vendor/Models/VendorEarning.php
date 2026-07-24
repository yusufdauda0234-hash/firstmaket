<?php

namespace App\Modules\Vendor\Models;

use App\Shared\Enums\VendorEarningType;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable vendor earnings ledger row (docs/FirstMaket-Database_Schema.md
 * section 9) — fully separate from customer wallets and savings. Positive
 * `earning` rows are unique per order (credit exactly once, only after
 * delivery confirmation); `payout` rows are negative; corrections are new
 * `adjustment` rows, never edits. All writes go through EarningsService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $vendor_id
 * @property int|null $order_id
 * @property VendorEarningType $type
 * @property int $amount_kobo
 * @property int $balance_before_kobo
 * @property int $balance_after_kobo
 * @property int|null $payout_item_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property-read VendorProfile $vendor
 */
class VendorEarning extends Model
{
    use HasUuid;

    const UPDATED_AT = null;

    protected $fillable = [
        'vendor_id',
        'order_id',
        'type',
        'amount_kobo',
        'balance_before_kobo',
        'balance_after_kobo',
        'payout_item_id',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => VendorEarningType::class,
            'amount_kobo' => 'integer',
            'balance_before_kobo' => 'integer',
            'balance_after_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VendorProfile, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }
}
