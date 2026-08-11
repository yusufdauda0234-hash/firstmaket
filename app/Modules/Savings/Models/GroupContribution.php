<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's money arriving on a group's plan.
 *
 * Append-only, and always tied to the PlanPayment it came from: a share is
 * only ever a claim about money that verifiably arrived, never a number
 * somebody typed.
 */
class GroupContribution extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['group_plan_id', 'user_id', 'plan_payment_id', 'amount_kobo'];

    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<GroupPlan, $this> */
    public function groupPlan(): BelongsTo { return $this->belongsTo(GroupPlan::class); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** @return BelongsTo<PlanPayment, $this> */
    public function planPayment(): BelongsTo { return $this->belongsTo(PlanPayment::class, 'plan_payment_id'); }
}
