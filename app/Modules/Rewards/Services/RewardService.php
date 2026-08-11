<?php

namespace App\Modules\Rewards\Services;

use App\Models\User;
use App\Modules\Rewards\Models\RewardTier;
use App\Modules\Rewards\Models\UserReward;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Database\Eloquent\Collection;

class RewardService
{
    public function recalculate(User $user): UserReward
    {
        $lifetime = (int) SavingsGoal::query()
            ->where('user_id', $user->id)
            ->where('status', SavingsGoalStatus::Fulfilled)
            ->sum('target_kobo');

        $tier = $this->tierFor($lifetime);

        return UserReward::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'reward_tier_id' => $tier->id,
                'lifetime_completed_savings' => $lifetime,
                'awarded_at' => now(),
            ],
        )->load('tier');
    }

    /** @return Collection<int, RewardTier> */
    public function activeTiers(): Collection
    {
        return RewardTier::query()
            ->where('status', true)
            ->orderBy('minimum_completed_savings')
            ->get();
    }

    public function tierFor(int $lifetime): RewardTier
    {
        return RewardTier::query()
            ->where('status', true)
            ->where('minimum_completed_savings', '<=', $lifetime)
            ->orderByDesc('minimum_completed_savings')
            ->firstOrFail();
    }
}
