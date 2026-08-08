<?php

namespace App\Modules\Payments\Models;

use App\Models\User;
use App\Modules\Savings\Models\SavingsTransaction;
use App\Shared\Enums\ReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reconciliation line comparing a Paystack settlement entry against the
 * internal ledger (docs/FirstMaket-Database_Schema.md section 7).
 *
 * @property int $id
 * @property int $settlement_import_id
 * @property string $paystack_reference
 * @property int|null $savings_transaction_id
 * @property int|null $provider_amount_kobo
 * @property int|null $ledger_amount_kobo
 * @property ReconciliationStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read SettlementImport $settlementImport
 * @property-read SavingsTransaction|null $savingsTransaction
 */
class SettlementReconciliationItem extends Model
{
    protected $fillable = [
        'settlement_import_id',
        'paystack_reference',
        'savings_transaction_id',
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

    /** @return BelongsTo<SavingsTransaction, $this> */
    public function savingsTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
