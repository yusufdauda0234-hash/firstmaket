<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Shared\Enums\SavingsStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Plan credit — money the customer already paid that is waiting to go onto
 * their next Pay Small Small plan.
 *
 * `credit_kobo` is the live figure. It is only ever created by cancelling a
 * plan or by a refund after a vendor rejection, and only ever spent on
 * another plan; there is no deposit path and no withdrawal path.
 * `balance_kobo` is a leftover from the retired wallet and stays at zero.
 *
 * Stored in kobo and only ever mutated through SavingsService inside a
 * row-locked transaction — never written directly.
 *
 * @property int $id
 * @property int $user_id
 * @property string $currency
 * @property int $balance_kobo Retired wallet column; always zero.
 * @property int $credit_kobo
 * @property SavingsStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Collection<int, SavingsTransaction> $transactions
 */
class Savings extends Model
{
    protected $table = 'savings';

    protected $fillable = [
        'user_id',
        'currency',
        'balance_kobo',
        'credit_kobo',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'balance_kobo' => 'integer',
            'credit_kobo' => 'integer',
            'status' => SavingsStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SavingsTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class, 'savings_id');
    }

    public function isActive(): bool
    {
        return $this->status === SavingsStatus::Active;
    }
}
