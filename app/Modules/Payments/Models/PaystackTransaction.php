<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Models\SavingsTransaction;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Paystack charge — a card checkout or a plan instalment (docs/FirstMaket-Database_Schema.md
 * section 7). Created as Pending at initialization; only moved to Success and
 * linked to a ledger row by a signature-verified webhook.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $savings_transaction_id
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
 * @property-read SavingsTransaction|null $savingsTransaction
 */
class PaystackTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'purpose',
        'checkout_session_id',
        'shipment_id',
        'savings_goal_id',
        'savings_transaction_id',
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

    /** @return BelongsTo<CheckoutSession, $this> */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<SavingsGoal, $this> */
    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SavingsTransaction, $this> */
    public function savingsTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class);
    }
}
