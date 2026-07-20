<?php

namespace App\Modules\Vendor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vendor payout destination (docs/firstmarket-Database_Schema.md section 9).
 * The account number is encrypted at rest; the account name is resolved via
 * Paystack before verification. Payouts only go to accounts with verified_at
 * set, and each vendor has exactly one active account.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $bank_code
 * @property string|null $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property string|null $paystack_recipient_code
 * @property Carbon|null $verified_at
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read VendorProfile $vendor
 */
class VendorBankAccount extends Model
{
    protected $fillable = [
        'vendor_id',
        'bank_code',
        'bank_name',
        'account_number',
        'account_name',
        'paystack_recipient_code',
        'verified_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<VendorProfile, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorProfile::class, 'vendor_id');
    }
}
