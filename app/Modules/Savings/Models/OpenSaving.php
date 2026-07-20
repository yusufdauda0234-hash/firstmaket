<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Modules\Wallet\Models\Wallet;
use App\Shared\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The single Open Savings pot per customer (docs/firstmarket-Database_Schema.md
 * section 8): a no-target savings balance funded only from the wallet, which
 * can be allocated or redirected into Product Target Plans but never
 * withdrawn as cash. Balance changes go through OpenSavingsService only.
 *
 * @property int $id
 * @property int $user_id
 * @property int $wallet_id
 * @property int $balance_kobo
 * @property WalletStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Wallet $wallet
 */
class OpenSaving extends Model
{
    protected $table = 'open_savings';

    protected $fillable = [
        'user_id',
        'wallet_id',
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

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
