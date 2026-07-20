<?php

namespace App\Modules\Vendor\Models;

use App\Shared\Enums\PayoutItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One vendor's line inside a payout batch
 * (docs/firstmarket-Database_Schema.md section 9). The amount equals the
 * vendor's cleared earnings balance at generation. A failed transfer keeps
 * the ledger intact — the negative `payout` ledger row is written only when
 * the item is marked paid.
 *
 * @property int $id
 * @property int $batch_id
 * @property int $vendor_id
 * @property int $bank_account_id
 * @property int $amount_kobo
 * @property PayoutItemStatus $status
 * @property string|null $paystack_transfer_reference
 * @property string|null $failure_reason
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read VendorPayoutBatch $batch
 * @property-read VendorProfile $vendor
 * @property-read VendorBankAccount $bankAccount
 */
class VendorPayoutItem extends Model
{
    protected $fillable = [
        'batch_id',
        'vendor_id',
        'bank_account_id',
        'amount_kobo',
        'status',
        'paystack_transfer_reference',
        'failure_reason',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'status' => PayoutItemStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VendorPayoutBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(VendorPayoutBatch::class, 'batch_id');
    }

    /** @return BelongsTo<VendorProfile, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }

    /** @return BelongsTo<VendorBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(VendorBankAccount::class, 'bank_account_id');
    }
}
