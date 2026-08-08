<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A receipt is issued in the same transaction as the ledger credit it belongs
 * to (docs/FirstMaket_Implementation_Plan.md Sprint 4). Receipt rows are a
 * financial audit artifact and are retained, never purged.
 *
 * @property int $id
 * @property string $uuid
 * @property int $savings_transaction_id
 * @property int $user_id
 * @property string $receipt_number
 * @property int $amount_kobo
 * @property string $currency
 * @property string|null $channel
 * @property Carbon $issued_at
 * @property Carbon|null $emailed_at
 * @property string|null $pdf_path
 * @property-read SavingsTransaction $savingsTransaction
 * @property-read User $user
 */
class Receipt extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'savings_transaction_id',
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

    /** @return BelongsTo<SavingsTransaction, $this> */
    public function savingsTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'savings_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
