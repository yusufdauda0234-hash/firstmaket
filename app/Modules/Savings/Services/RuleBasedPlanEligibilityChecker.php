<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Shared\Contracts\PlanEligibilityContract;
use App\Shared\Enums\PlanPaymentMode;
use App\Shared\Enums\PlanStatus;

/**
 * Sprint 8 rule-based multi-product plan eligibility checker
 * (docs/FirstMaket_Implementation_Plan.md Sprint 8). Eligible when all of:
 * account is at least 30 days old, at least one prior Completed plan or two
 * delivered Pay At Once orders (proven follow-through), and no more than one
 * currently Cancelled plan (protects against serial abandoners). Sprint 9
 * swaps this for an AI-scored implementation behind the same contract.
 */
class RuleBasedPlanEligibilityChecker implements PlanEligibilityContract
{
    private const MINIMUM_ACCOUNT_AGE_DAYS = 30;

    private const MINIMUM_DELIVERED_PAY_AT_ONCE_ORDERS = 2;

    private const MAXIMUM_CANCELLED_PLANS = 1;

    public function reasonIneligible(User $user): ?string
    {
        if ($user->created_at === null || $user->created_at->gt(now()->subDays(self::MINIMUM_ACCOUNT_AGE_DAYS))) {
            return 'Your account needs to be at least '.self::MINIMUM_ACCOUNT_AGE_DAYS.' days old to bundle products into one plan.';
        }

        $hasCompletedPlan = ProductTargetPlan::query()
            ->where('user_id', $user->id)
            ->where('status', PlanStatus::Completed)
            ->exists();

        if (! $hasCompletedPlan) {
            $deliveredPayAtOnceOrders = Order::query()
                ->where('customer_id', $user->id)
                ->whereNotNull('delivered_at')
                ->whereHas('plan', fn ($query) => $query->where('payment_mode', PlanPaymentMode::PayAtOnce))
                ->count();

            if ($deliveredPayAtOnceOrders < self::MINIMUM_DELIVERED_PAY_AT_ONCE_ORDERS) {
                return 'Complete at least one plan, or receive '.self::MINIMUM_DELIVERED_PAY_AT_ONCE_ORDERS.' Pay At Once orders, before bundling products into one plan.';
            }
        }

        $cancelledPlanCount = ProductTargetPlan::query()
            ->where('user_id', $user->id)
            ->where('status', PlanStatus::Cancelled)
            ->count();

        if ($cancelledPlanCount > self::MAXIMUM_CANCELLED_PLANS) {
            return 'Too many cancelled plans on your account to start a bundled plan right now.';
        }

        return null;
    }
}
