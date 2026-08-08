<?php

namespace App\Modules\Cart\Models;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Savings\Models\SavingsTransaction;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One full-payment cart checkout (Sprint 8,
 * docs/FirstMaket-Database_Schema.md section 8a). Groups the orders it
 * creates — one per unit purchased, possibly across several vendors — for
 * "placed together" display and receipts. The delivery address is captured
 * once here, upfront, before the single savings debit for the cart total;
 * A savings goal reaching its target also creates one of these, so orders
 * are grouped and receipted identically however they were paid for.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $savings_transaction_id
 * @property int $total_amount_kobo Goods plus delivery — what was debited.
 * @property int $shipping_fee_kobo
 * @property string $payment_method
 * @property string $status pending | paid | abandoned
 * @property string|null $paystack_reference
 * @property Carbon|null $paid_at
 * @property array<int, array{product_id: int, quantity: int, unit_price_kobo: int}>|null $items_snapshot
 * @property string $delivery_address
 * @property string $state
 * @property string $lga
 * @property string|null $recipient_name
 * @property string|null $recipient_phone
 * @property string|null $landmark
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read SavingsTransaction $savingsTransaction
 * @property-read Collection<int, Order> $orders
 */
class CheckoutSession extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'savings_transaction_id',
        'total_amount_kobo',
        'shipping_fee_kobo',
        'payment_method',
        'status',
        'paystack_reference',
        'paid_at',
        'items_snapshot',
        'delivery_address',
        'state',
        'lga',
        'recipient_name',
        'recipient_phone',
        'landmark',
        'promo_code_id',
        'promo_discount_kobo',
        'collect_on_delivery_kobo',
    ];

    protected function casts(): array
    {
        return [
            'total_amount_kobo' => 'integer',
            'shipping_fee_kobo' => 'integer',
            'promo_discount_kobo' => 'integer',
            'collect_on_delivery_kobo' => 'integer',
            'items_snapshot' => 'array',
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The code applied to this checkout, if any.
     *
     * Frozen onto the session rather than re-read at completion: the webhook
     * lands minutes or hours later, and the customer is owed the discount
     * they were quoted even if the code has since been switched off.
     *
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SavingsTransaction, $this> */
    public function savingsTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'savings_transaction_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
