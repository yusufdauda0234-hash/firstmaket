<?php

namespace App\Modules\Referrals\Listeners;

use App\Modules\Referrals\Models\Referral;
use App\Modules\Referrals\Notifications\ReferralRewardNotification;
use App\Modules\Savings\Events\PlanCompleted;
use Illuminate\Support\Facades\DB;

class CreditReferralReward
{
    public function handle(PlanCompleted $event): void
    {
        $referral = Referral::query()
            ->where('referred_id', $event->userId)
            ->where('status', 'pending')
            ->whereNull('qualified_plan_id')
            ->with('referrer')
            ->first();

        if ($referral === null) {
            return;
        }

        $updated = DB::transaction(function () use ($referral, $event): int {
            $updated = Referral::query()
                ->whereKey($referral->id)
                ->where('status', 'pending')
                ->whereNull('qualified_plan_id')
                ->update([
                    'status' => 'earned',
                    'qualified_plan_id' => $event->planId,
                    'reward_credited_at' => now(),
                    'updated_at' => now(),
                ]);

            return $updated;
        });

        if ($updated === 1) {
            $referral->referrer?->notify(new ReferralRewardNotification($referral->fresh()));
        }
    }
}
