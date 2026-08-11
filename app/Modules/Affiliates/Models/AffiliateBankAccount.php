<?php

namespace App\Modules\Affiliates\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an affiliate's payout is sent. Nothing is paid to an unverified
 * account — staff confirm the name matches before it can appear in a batch,
 * the same rule vendor payouts follow.
 */
class AffiliateBankAccount extends Model
{
    protected $fillable = [
        'affiliate_id', 'bank_name', 'bank_code', 'account_number',
        'account_name', 'verified_at', 'verified_by', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest: a leaked dump must not hand out payout
            // destinations.
            'account_number' => 'encrypted',
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isPayable(): bool
    {
        return $this->is_active && $this->verified_at !== null;
    }

    /** Last four digits only — enough to recognise, useless if leaked. */
    public function maskedNumber(): string
    {
        $number = (string) $this->account_number;

        return str_repeat('•', max(0, mb_strlen($number) - 4)).mb_substr($number, -4);
    }
}
