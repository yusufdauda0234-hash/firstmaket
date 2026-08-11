<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyGroupMember extends Model
{
    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    protected $fillable = ['family_group_id', 'user_id', 'status', 'shares_progress', 'joined_at'];

    protected function casts(): array
    {
        return ['shares_progress' => 'boolean', 'joined_at' => 'datetime'];
    }

    /** @return BelongsTo<FamilyGroup, $this> */
    public function familyGroup(): BelongsTo { return $this->belongsTo(FamilyGroup::class); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
