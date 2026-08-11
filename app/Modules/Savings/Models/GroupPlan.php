<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Several people saving toward one basket.
 *
 * There is no group balance. Every contribution lands on the single
 * SavingsGoal behind this group, and is written into group_contributions
 * against the member who made it — so "whose money is this" has an answer
 * long after the goods arrive.
 */
class GroupPlan extends Model
{
    use HasUuid;

    public const STATUS_OPEN = 'open';

    public const STATUS_FUNDED = 'funded';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['savings_goal_id', 'organiser_id', 'name', 'description', 'status', 'invite_code'];

    /** @return BelongsTo<SavingsGoal, $this> */
    public function goal(): BelongsTo { return $this->belongsTo(SavingsGoal::class, 'savings_goal_id'); }

    /** @return BelongsTo<User, $this> */
    public function organiser(): BelongsTo { return $this->belongsTo(User::class, 'organiser_id'); }

    /** @return HasMany<GroupPlanMember, $this> */
    public function members(): HasMany { return $this->hasMany(GroupPlanMember::class); }

    /** @return HasMany<GroupContribution, $this> */
    public function contributions(): HasMany { return $this->hasMany(GroupContribution::class); }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * What each member has put in, keyed by user id.
     *
     * @return array<int, int>
     */
    public function sharesByUser(): array
    {
        return $this->contributions()
            ->selectRaw('user_id, SUM(amount_kobo) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
