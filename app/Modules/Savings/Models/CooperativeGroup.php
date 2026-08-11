<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rotating savings group — ajo/esusu, which is the model Pay Small Small
 * is named after.
 *
 * The one change from how it works offline: nobody ever receives cash. Each
 * cycle, every member pays their contribution into the plan of whoever's
 * turn it is. The "payout" is that member's plan jumping forward, which is
 * what lets a rotating scheme exist in a system with no withdrawals at all.
 */
class CooperativeGroup extends Model
{
    /** Members still joining and the rotation not yet fixed. */
    public const STATUS_FORMING = 'forming';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    use HasUuid;

    protected $fillable = ['organiser_id', 'name', 'description', 'contribution_kobo', 'cadence', 'status', 'invite_code'];

    protected function casts(): array
    {
        return ['contribution_kobo' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function organiser(): BelongsTo { return $this->belongsTo(User::class, 'organiser_id'); }

    /** @return HasMany<CooperativeMember, $this> */
    public function members(): HasMany { return $this->hasMany(CooperativeMember::class); }

    /** @return HasMany<CooperativeCycle, $this> */
    public function cycles(): HasMany { return $this->hasMany(CooperativeCycle::class); }

    public function activeMembers()
    {
        return $this->members()->where('status', CooperativeMember::STATUS_ACTIVE)->orderBy('position');
    }
}
