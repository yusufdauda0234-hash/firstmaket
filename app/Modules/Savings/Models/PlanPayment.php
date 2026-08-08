<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One installment paid into a plan. Append-only with paid_before/after
 * captured, like the savings ledger — the plan's running total must always
 * be reconstructable from its payments.
 *
 * @property int $id
 * @property string $uuid
 * @property int $savings_goal_id
 * @property int $user_id
 * @property int $amount_kobo
 * @property int $paid_before_kobo
 * @property int $paid_after_kobo
 * @property string $source card | credit
 * @property string $reference
 * @property Carbon $created_at
 * @property-read SavingsGoal $goal
 * @property-read User $user
 */
class PlanPayment extends Model
{
    use HasUuid;

    const UPDATED_AT = null;

    protected $fillable = [
        'savings_goal_id',
        'user_id',
        'amount_kobo',
        'paid_before_kobo',
        'paid_after_kobo',
        'source',
        'reference',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'paid_before_kobo' => 'integer',
            'paid_after_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SavingsGoal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
