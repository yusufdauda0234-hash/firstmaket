<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One turn of the rotation. Every active member contributes; the money all
 * lands on the beneficiary's own plan.
 */
class CooperativeCycle extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'cooperative_group_id', 'cycle_number', 'beneficiary_user_id',
        'beneficiary_goal_id', 'status', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['cycle_number' => 'integer', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<CooperativeGroup, $this> */
    public function group(): BelongsTo { return $this->belongsTo(CooperativeGroup::class, 'cooperative_group_id'); }

    /** @return BelongsTo<User, $this> */
    public function beneficiary(): BelongsTo { return $this->belongsTo(User::class, 'beneficiary_user_id'); }

    /** @return BelongsTo<SavingsGoal, $this> */
    public function beneficiaryGoal(): BelongsTo { return $this->belongsTo(SavingsGoal::class, 'beneficiary_goal_id'); }

    /** @return HasMany<CooperativeContribution, $this> */
    public function contributions(): HasMany { return $this->hasMany(CooperativeContribution::class); }
}
