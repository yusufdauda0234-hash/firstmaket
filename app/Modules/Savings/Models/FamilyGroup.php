<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A read-only window onto how a household is doing.
 *
 * Deliberately holds no money and has no plan of its own. Nothing about a
 * family group can move a naira — it aggregates summaries of members' own
 * separate plans, and only for members who have opted in.
 */
class FamilyGroup extends Model
{
    use HasUuid;

    protected $fillable = ['owner_id', 'name', 'invite_code'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }

    /** @return HasMany<FamilyGroupMember, $this> */
    public function members(): HasMany { return $this->hasMany(FamilyGroupMember::class); }
}
