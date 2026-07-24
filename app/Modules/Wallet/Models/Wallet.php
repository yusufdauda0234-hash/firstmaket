<?php

namespace App\Modules\Wallet\Models;

use App\Models\User;
use App\Shared\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Deposit-only customer wallet (docs/FirstMaket-Database_Schema.md section 7).
 * The balance is stored in kobo and is only ever mutated through
 * WalletService inside a row-locked database transaction — never written
 * directly. There is no withdrawal path.
 *
 * @property int $id
 * @property int $user_id
 * @property string $currency
 * @property int $balance_kobo
 * @property WalletStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Collection<int, WalletTransaction> $transactions
 */
class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
        'balance_kobo',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'balance_kobo' => 'integer',
            'status' => WalletStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalletTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function isActive(): bool
    {
        return $this->status === WalletStatus::Active;
    }
}
