<?php

namespace App\Modules\Savings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CooperativeMember extends Model
{
    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXITED = 'exited';

    protected $fillable = ['cooperative_group_id', 'user_id', 'position', 'status', 'joined_at'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'joined_at' => 'datetime'];
    }

    /** @return BelongsTo<CooperativeGroup, $this> */
    public function group(): BelongsTo { return $this->belongsTo(CooperativeGroup::class, 'cooperative_group_id'); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
