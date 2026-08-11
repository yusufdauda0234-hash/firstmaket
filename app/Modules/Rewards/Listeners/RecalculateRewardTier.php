<?php

namespace App\Modules\Rewards\Listeners;

use App\Models\User;
use App\Modules\Rewards\Services\RewardService;
use App\Modules\Savings\Events\PlanCompleted;

class RecalculateRewardTier
{
    public function __construct(private readonly RewardService $rewards) {}

    public function handle(PlanCompleted $event): void
    {
        $user = User::query()->find($event->userId);

        if ($user !== null) {
            $this->rewards->recalculate($user);
        }
    }
}
