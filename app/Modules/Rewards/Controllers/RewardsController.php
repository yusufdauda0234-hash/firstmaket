<?php

namespace App\Modules\Rewards\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Rewards\Models\UserReward;
use App\Modules\Rewards\Services\RewardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RewardsController extends Controller
{
    public function index(Request $request, RewardService $rewards): Response
    {
        $reward = UserReward::query()
            ->with('tier')
            ->where('user_id', $request->user()->id)
            ->first();
        $tiers = $rewards->activeTiers();
        $lifetime = $reward?->lifetime_completed_savings ?? 0;
        $currentTier = $reward?->tier ?? $rewards->tierFor($lifetime);
        $nextTier = $tiers->first(fn ($tier) => $tier->minimum_completed_savings > $lifetime);

        return Inertia::render('Account/Rewards', [
            'current' => [
                'name' => $currentTier->name,
                'minimumCompletedSavings' => $currentTier->minimum_completed_savings,
                'benefits' => $currentTier->benefits,
                'awardedAt' => $reward?->awarded_at?->toDateString(),
            ],
            'lifetimeCompletedSavingsKobo' => $lifetime,
            'nextTier' => $nextTier ? [
                'name' => $nextTier->name,
                'minimumCompletedSavings' => $nextTier->minimum_completed_savings,
            ] : null,
            'tiers' => $tiers->map(fn ($tier) => [
                'name' => $tier->name,
                'minimumCompletedSavings' => $tier->minimum_completed_savings,
                'benefits' => $tier->benefits,
            ])->values(),
        ]);
    }
}
