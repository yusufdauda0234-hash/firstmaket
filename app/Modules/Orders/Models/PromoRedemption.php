<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One use of a promo code.
 *
 * Released rather than deleted when an order is refunded, so a customer is
 * not charged a use for a vendor's failure while the fact that it happened
 * stays on the record.
 *
 * @property int $id
 * @property int $promo_code_id
 * @property int $user_id
 * @property int|null $checkout_session_id
 * @property int $discount_kobo
 * @property Carbon|null $released_at
 */
class PromoRedemption extends Model
{
    protected $fillable = [
        'promo_code_id',
        'user_id',
        'checkout_session_id',
        'discount_kobo',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_kobo' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PromoCode, $this> */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CheckoutSession, $this> */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }
}
