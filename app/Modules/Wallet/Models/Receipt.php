<?php

namespace App\Modules\Wallet\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A receipt is issued in the same transaction as the ledger credit it belongs
 * to (docs/firstmarket_Implementation_Plan.md Sprint 4). Receipt rows are a
 * financial audit artifact and are retained, never purged.
 *
 * @property int $id
 * @property string $uuid
 * @property int $wallet_transaction_id
 * @property int $user_id
 * @property string $receipt_number
 * @property int $amount_kobo
 * @property string $currency
 * @property string|null $channel
 * @property Carbon $issued_at
 * @property Carbon|null $emailed_at
 * @property string|null $pdf_path
 * @property-read WalletTransaction $walletTransaction
 * @property-read User $user
 */
class Receipt extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'wallet_transaction_id',
        'user_id',
        'receipt_number',
        'amount_kobo',
        'currency',
        'channel',
        'issued_at',
        'emailed_at',
        'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'issued_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WalletTransaction, $this> */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
