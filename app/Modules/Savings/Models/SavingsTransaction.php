<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Shared\Enums\LedgerDirection;
use App\Shared\Enums\SavingsTransactionType;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Immutable ledger row (docs/FirstMaket-Database_Schema.md section 7). Rows
 * are append-only: created once with balance_before/after captured, never
 * updated or deleted. `reference` is unique — for deposits it is the Paystack
 * reference, which is what makes webhook crediting idempotent.
 *
 * @property int $id
 * @property string $uuid
 * @property int $savings_id
 * @property int $user_id
 * @property SavingsTransactionType $type
 * @property LedgerDirection $direction
 * @property int $amount_kobo
 * @property int $balance_before_kobo
 * @property int $balance_after_kobo
 * @property string $reference
 * @property string|null $receipt_number
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property-read Savings $savings
 * @property-read User $user
 * @property-read Receipt|null $receipt
 */
class SavingsTransaction extends Model
{
    use HasUuid;

    const UPDATED_AT = null;

    protected $fillable = [
        'savings_id',
        'user_id',
        'type',
        'direction',
        'amount_kobo',
        'balance_before_kobo',
        'balance_after_kobo',
        'reference',
        'receipt_number',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => SavingsTransactionType::class,
            'direction' => LedgerDirection::class,
            'amount_kobo' => 'integer',
            'balance_before_kobo' => 'integer',
            'balance_after_kobo' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Savings, $this> */
    public function savings(): BelongsTo
    {
        return $this->belongsTo(Savings::class, 'savings_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<Receipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class, 'savings_transaction_id');
    }
}
