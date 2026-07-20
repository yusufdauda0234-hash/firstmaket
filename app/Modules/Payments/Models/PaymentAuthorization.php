<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Reusable Paystack card authorization, stored for Phase 2 scheduled
 * automatic debit (docs/firstmarket-Database_Schema.md section 7). Sprint 4
 * only captures this metadata from successful charges — nothing charges it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $authorization_code
 * @property string|null $signature
 * @property string|null $card_type
 * @property string|null $bank
 * @property string|null $last4
 * @property string|null $exp_month
 * @property string|null $exp_year
 * @property bool $reusable
 * @property bool $active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class PaymentAuthorization extends Model
{
    protected $fillable = [
        'user_id',
        'authorization_code',
        'signature',
        'card_type',
        'bank',
        'last4',
        'exp_month',
        'exp_year',
        'reusable',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'reusable' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
