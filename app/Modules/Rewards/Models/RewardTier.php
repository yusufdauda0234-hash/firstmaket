<?php

namespace App\Modules\Rewards\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardTier extends Model
{
    protected $fillable = [
        'name',
        'minimum_completed_savings',
        'benefits',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'minimum_completed_savings' => 'integer',
            'benefits' => 'array',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<UserReward, $this> */
    public function userRewards(): HasMany
    {
        return $this->hasMany(UserReward::class);
    }
}
