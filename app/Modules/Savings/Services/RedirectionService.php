<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Savings\Models\OpenSaving;
use App\Modules\Savings\Models\PlanRedirection;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\ContributionSource;
use App\Shared\Enums\PlanStatus;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\WalletStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns savings redirections (docs/firstmarket_Implementation_Plan.md Sprint
 * 5, PRD Product Tracker rules): moving the full Open Savings balance into a
 * plan, or switching an active plan to a different product carrying its full
 * balance to a freshly locked price. Allowed only while the target/source
 * plan is Active — never after Ready for Delivery — and every redirection is
 * recorded in plan_redirections and audit-logged. No cash ever leaves.
 */
class RedirectionService
{
    public function __construct(
        private readonly PlanService $planService,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /**
     * Redirect the full Open Savings balance into an active plan. If the
     * carried balance covers the remaining target, the plan becomes Ready
     * for Delivery immediately (PRD rule); any surplus beyond the target
     * stays in Open Savings — it is never lost and never refunded as cash.
     */
    public function redirectOpenSavings(User $user, ProductTargetPlan $plan): ProductTargetPlan
    {
        return DB::transaction(function () use ($user, $plan) {
            /** @var ProductTargetPlan $plan */
            $plan = ProductTargetPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($plan->user_id !== $user->id) {
                throw ValidationException::withMessages(['plan' => 'This plan does not belong to you.']);
            }

            if ($plan->status !== PlanStatus::Active) {
                throw ValidationException::withMessages(['plan' => 'Redirection is only allowed while the plan is active.']);
            }

            $openSaving = OpenSaving::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($openSaving === null || $openSaving->status !== WalletStatus::Active || $openSaving->balance_kobo <= 0) {
                throw ValidationException::withMessages(['amount' => 'There is no Open Savings balance to redirect.']);
            }

            // Carry at most what the plan still needs; the rest stays in the pot.
            $transfer = min($openSaving->balance_kobo, $plan->remaining_balance_kobo);

            $openSaving->forceFill(['balance_kobo' => $openSaving->balance_kobo - $transfer])->save();

            $redirection = PlanRedirection::query()->create([
                'user_id' => $user->id,
                'source_type' => 'open_savings',
                'source_id' => $openSaving->id,
                'target_plan_id' => $plan->id,
                'old_product_id' => null,
                'new_product_id' => $plan->product_id,
                'balance_transferred_kobo' => $transfer,
                'old_target_price_kobo' => null,
                'new_target_price_kobo' => $plan->target_price_kobo,
                'created_at' => now(),
            ]);

            $plan = $this->planService->applyContribution($user, $plan, $transfer, ContributionSource::Redirection, null);

            $this->auditLogger->log(
                actor: $user,
                subject: $redirection,
                action: 'savings.redirection',
                newValues: [
                    'source_type' => 'open_savings',
                    'target_plan_id' => $plan->id,
                    'balance_transferred_kobo' => $transfer,
                ],
            );

            return $plan;
        });
    }

    /**
     * Switch an active plan to a different approved product: the full saved
     * balance carries over and the new product's current price is locked as
     * the new target. Blocked once Ready for Delivery. If the carried
     * balance already covers the new target, the plan becomes Ready for
     * Delivery immediately.
     */
    public function switchProduct(User $user, ProductTargetPlan $plan, Product $newProduct): ProductTargetPlan
    {
        if ($newProduct->status !== ProductStatus::Approved) {
            throw ValidationException::withMessages(['product' => 'This product is not available.']);
        }

        return DB::transaction(function () use ($user, $plan, $newProduct) {
            /** @var ProductTargetPlan $plan */
            $plan = ProductTargetPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($plan->user_id !== $user->id) {
                throw ValidationException::withMessages(['plan' => 'This plan does not belong to you.']);
            }

            if ($plan->status !== PlanStatus::Active) {
                throw ValidationException::withMessages(['plan' => 'Redirection is only allowed while the plan is active.']);
            }

            if ($newProduct->id === $plan->product_id) {
                throw ValidationException::withMessages(['product' => 'The plan already targets this product.']);
            }

            $oldProductId = $plan->product_id;
            $oldTargetPrice = $plan->target_price_kobo;

            $redirection = PlanRedirection::query()->create([
                'user_id' => $user->id,
                'source_type' => 'plan',
                'source_id' => $plan->id,
                'target_plan_id' => $plan->id,
                'old_product_id' => $oldProductId,
                'new_product_id' => $newProduct->id,
                'balance_transferred_kobo' => $plan->amount_saved_kobo,
                'old_target_price_kobo' => $oldTargetPrice,
                'new_target_price_kobo' => $newProduct->price_kobo,
                'created_at' => now(),
            ]);

            // Re-target and re-lock at the new product's current price, then
            // recompute all derived math from the untouched contributions.
            $plan->forceFill([
                'product_id' => $newProduct->id,
                'target_price_kobo' => $newProduct->price_kobo,
            ]);
            $this->planService->recalculate($plan);

            $this->auditLogger->log(
                actor: $user,
                subject: $redirection,
                action: 'savings.redirection',
                newValues: [
                    'source_type' => 'plan',
                    'old_product_id' => $oldProductId,
                    'new_product_id' => $newProduct->id,
                    'balance_transferred_kobo' => $plan->amount_saved_kobo,
                    'old_target_price_kobo' => $oldTargetPrice,
                    'new_target_price_kobo' => $newProduct->price_kobo,
                ],
            );

            return $plan;
        });
    }
}
