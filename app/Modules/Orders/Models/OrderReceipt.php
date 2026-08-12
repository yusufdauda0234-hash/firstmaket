<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's receipt for one checkout.
 *
 * Financial record: rows are written once and never edited. Anything that
 * would change a figure here — a refund, a returned item — belongs on its own
 * document, not as a quiet rewrite of what the customer was told they paid.
 *
 * @property int $id
 * @property string $uuid
 * @property string $receipt_number
 * @property int $checkout_session_id
 * @property int $customer_id
 * @property string $currency
 * @property int $subtotal_kobo
 * @property int $shipping_kobo
 * @property int $discount_kobo
 * @property int $total_kobo
 * @property int $paid_kobo
 * @property int $collect_on_delivery_kobo
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property array<int, array<string, mixed>> $items_snapshot
 * @property array<string, mixed> $billed_to
 * @property Carbon $issued_at
 * @property Carbon|null $emailed_at
 */
class OrderReceipt extends Model
{
    use HasUuid;

    protected $fillable = [
        'receipt_number',
        'checkout_session_id',
        'customer_id',
        'currency',
        'subtotal_kobo',
        'shipping_kobo',
        'discount_kobo',
        'total_kobo',
        'paid_kobo',
        'collect_on_delivery_kobo',
        'payment_method',
        'payment_reference',
        'items_snapshot',
        'billed_to',
        'issued_at',
        'emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_kobo' => 'integer',
            'shipping_kobo' => 'integer',
            'discount_kobo' => 'integer',
            'total_kobo' => 'integer',
            'paid_kobo' => 'integer',
            'collect_on_delivery_kobo' => 'integer',
            'items_snapshot' => 'array',
            'billed_to' => 'array',
            'issued_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CheckoutSession, $this> */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** Still owed at the door. */
    public function isPaidInFull(): bool
    {
        return $this->collect_on_delivery_kobo === 0;
    }
}
