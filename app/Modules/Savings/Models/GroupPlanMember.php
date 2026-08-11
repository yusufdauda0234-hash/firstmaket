<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPlanMember extends Model
{
    public const ROLE_ORGANISER = 'organiser';

    public const ROLE_MEMBER = 'member';

    /** Asked, but has not agreed yet. Cannot contribute. */
    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    /** Left the group. Their past contributions stay on the ledger. */
    public const STATUS_EXITED = 'exited';

    protected $fillable = ['group_plan_id', 'user_id', 'role', 'status', 'joined_at', 'exited_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'exited_at' => 'datetime'];
    }

    /** @return BelongsTo<GroupPlan, $this> */
    public function groupPlan(): BelongsTo { return $this->belongsTo(GroupPlan::class); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
