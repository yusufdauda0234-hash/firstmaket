<?php

namespace App\Modules\Savings\Models;

use App\Modules\Wallet\Models\WalletTransaction;
use App\Shared\Enums\ContributionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One application of money to a Product Target Plan
 * (docs/firstmarket-Database_Schema.md section 8). wallet_transaction_id
 * links the wallet ledger debit when money came straight from the wallet;
 * it is null for Open Savings allocations and redirections, which do not
 * touch the wallet balance.
 *
 * @property int $id
 * @property int $plan_id
 * @property int|null $wallet_transaction_id
 * @property int $amount_kobo
 * @property Carbon $contribution_date
 * @property ContributionSource $source
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ProductTargetPlan $plan
 * @property-read WalletTransaction|null $walletTransaction
 */
class PlanContribution extends Model
{
    protected $fillable = [
        'plan_id',
        'wallet_transaction_id',
        'amount_kobo',
        'contribution_date',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'contribution_date' => 'date',
            'source' => ContributionSource::class,
        ];
    }

    /** @return BelongsTo<ProductTargetPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductTargetPlan::class, 'plan_id');
    }

    /** @return BelongsTo<WalletTransaction, $this> */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
