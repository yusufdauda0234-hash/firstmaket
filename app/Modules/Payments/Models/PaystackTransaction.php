<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Paystack charge for a wallet deposit (docs/FirstMaket-Database_Schema.md
 * section 7). Created as Pending at initialization; only moved to Success and
 * linked to a ledger row by a signature-verified webhook.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $wallet_transaction_id
 * @property string $paystack_reference
 * @property string|null $access_code
 * @property int $amount_kobo
 * @property string $currency
 * @property string|null $channel
 * @property PaystackTransactionStatus $status
 * @property Carbon|null $webhook_verified_at
 * @property array<string, mixed>|null $provider_payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read WalletTransaction|null $walletTransaction
 */
class PaystackTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_transaction_id',
        'paystack_reference',
        'access_code',
        'amount_kobo',
        'currency',
        'channel',
        'status',
        'webhook_verified_at',
        'provider_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'status' => PaystackTransactionStatus::class,
            'webhook_verified_at' => 'datetime',
            'provider_payload' => 'array',
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
}
