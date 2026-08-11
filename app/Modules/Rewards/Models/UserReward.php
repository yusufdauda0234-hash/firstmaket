<?php

namespace App\Modules\Rewards\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReward extends Model
{
    protected $fillable = [
        'user_id',
        'reward_tier_id',
        'lifetime_completed_savings',
        'awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'lifetime_completed_savings' => 'integer',
            'awarded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RewardTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(RewardTier::class, 'reward_tier_id');
    }
}
