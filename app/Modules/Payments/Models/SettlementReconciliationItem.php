<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Wallet\Models\WalletTransaction;
use App\Shared\Enums\ReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reconciliation line comparing a Paystack settlement entry against the
 * internal ledger (docs/firstmarket-Database_Schema.md section 7).
 *
 * @property int $id
 * @property int $settlement_import_id
 * @property string $paystack_reference
 * @property int|null $wallet_transaction_id
 * @property int|null $provider_amount_kobo
 * @property int|null $ledger_amount_kobo
 * @property ReconciliationStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read SettlementImport $settlementImport
 * @property-read WalletTransaction|null $walletTransaction
 */
class SettlementReconciliationItem extends Model
{
    protected $fillable = [
        'settlement_import_id',
        'paystack_reference',
        'wallet_transaction_id',
        'provider_amount_kobo',
        'ledger_amount_kobo',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_amount_kobo' => 'integer',
            'ledger_amount_kobo' => 'integer',
            'status' => ReconciliationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SettlementImport, $this> */
    public function settlementImport(): BelongsTo
    {
        return $this->belongsTo(SettlementImport::class);
    }

    /** @return BelongsTo<WalletTransaction, $this> */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
