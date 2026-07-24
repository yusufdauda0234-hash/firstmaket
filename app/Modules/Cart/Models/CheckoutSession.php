<?php

namespace App\Modules\Cart\Models;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Wallet\Models\WalletTransaction;
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
 * once here, upfront, before the single wallet debit for the cart total;
 * this is the pay-in-full branch of checkout, as opposed to a Product
 * Target Plan, which still asks for the address once fully funded.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $wallet_transaction_id
 * @property int $total_amount_kobo
 * @property string $delivery_address
 * @property string $state
 * @property string $lga
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read WalletTransaction $walletTransaction
 * @property-read Collection<int, Order> $orders
 */
class CheckoutSession extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'wallet_transaction_id',
        'total_amount_kobo',
        'delivery_address',
        'state',
        'lga',
    ];

    protected function casts(): array
    {
        return [
            'total_amount_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<WalletTransaction, $this> */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
