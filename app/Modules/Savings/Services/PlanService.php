<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Savings\Events\PlanReadyForDelivery;
use App\Modules\Savings\Models\OpenSaving;
use App\Modules\Savings\Models\PlanContribution;
use App\Modules\Savings\Models\PlanItem;
use App\Modules\Savings\Models\PlanStatusEvent;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Contracts\PlanEligibilityContract;
use App\Shared\Enums\ContributionSource;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\PlanPaymentMode;
use App\Shared\Enums\PlanStatus;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\WalletStatus;
use App\Shared\Enums\WalletTransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns every Product Target Plan state change (docs/FirstMaket_Implementation_Plan.md
 * Sprint 5): creation with price locking, contributions from wallet or Open
 * Savings, progress/expected-completion recalculation, Pay At Once, and
 * pause/resume. Redirections live in RedirectionService. Emits
 * PlanReadyForDelivery for the Orders module (Sprint 6) to consume. Sprint 8
 * adds createMultiProduct() for bundled (multi-vendor) plans.
 */
class PlanService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly AuditLoggerContract $auditLogger,
        private readonly PlanEligibilityContract $eligibility,
    ) {}

    /**
     * Start a plan toward an approved product, locking today's price
     * forever. There is no BVN/NIN identity verification requirement — Pay
     * At Once is a normal purchase and only needs wallet money.
     */
    public function create(
        User $user,
        Product $product,
        PlanPaymentMode $mode,
        ?PlanCadence $cadence = null,
        ?int $suggestedContributionKobo = null,
    ): ProductTargetPlan {
        if ($product->status !== ProductStatus::Approved) {
            throw ValidationException::withMessages(['product' => 'This product is not available.']);
        }

        if ($mode === PlanPaymentMode::Schedule) {
            if ($cadence === null) {
                throw ValidationException::withMessages(['cadence' => 'Choose a contribution schedule.']);
            }

            if ($suggestedContributionKobo !== null && $suggestedContributionKobo <= 0) {
                throw ValidationException::withMessages(['contribution' => 'Contribution must be greater than zero.']);
            }
        }

        return DB::transaction(function () use ($user, $product, $mode, $cadence, $suggestedContributionKobo) {
            $plan = ProductTargetPlan::query()->create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                // Locked now; vendor price edits never touch running plans.
                'target_price_kobo' => $product->price_kobo,
                'payment_mode' => $mode,
                'cadence' => $mode === PlanPaymentMode::Schedule ? $cadence : null,
                'suggested_contribution_kobo' => $suggestedContributionKobo,
                'amount_saved_kobo' => 0,
                'remaining_balance_kobo' => $product->price_kobo,
                'progress_percentage' => 0,
                'status' => PlanStatus::Active,
                'started_at' => now(),
                'expected_completion_date' => $this->projectFromSuggested($cadence, $suggestedContributionKobo, $product->price_kobo),
            ]);

            $this->recordStatusEvent($plan, null, PlanStatus::Active, $user);

            $this->auditLogger->log(
                actor: $user,
                subject: $plan,
                action: 'savings.plan_created',
                newValues: [
                    'product_id' => $product->id,
                    'target_price_kobo' => $plan->target_price_kobo,
                    'payment_mode' => $mode->value,
                    'cadence' => $cadence?->value,
                ],
            );

            return $plan;
        });
    }

    /**
     * Pay At Once: create the plan and pay the full locked price from the
     * wallet in one transaction. Reaches Ready for Delivery immediately.
     */
    public function payAtOnce(User $user, Product $product): ProductTargetPlan
    {
        return DB::transaction(function () use ($user, $product) {
            $plan = $this->create($user, $product, PlanPaymentMode::PayAtOnce);

            return $this->contributeFromWallet($user, $plan, $plan->target_price_kobo);
        });
    }

    /**
     * Bundle two or more cart items — possibly from different vendors —
     * into one multi-product plan with one combined target and one
     * contribution schedule (Sprint 8). Gated by PlanEligibilityContract;
     * single-product plans (create()) are never gated. Reaching Ready for
     * Delivery later creates one order per plan_item — never a subset — see
     * OrderService::createFromBundledPlan().
     *
     * @param  array<int, array{product: Product, quantity: int}>  $items
     */
    public function createMultiProduct(
        User $user,
        array $items,
        PlanCadence $cadence,
        ?int $suggestedContributionKobo = null,
    ): ProductTargetPlan {
        if (count($items) < 2) {
            throw ValidationException::withMessages(['items' => 'Select at least two products to bundle into one plan.']);
        }

        if (($reason = $this->eligibility->reasonIneligible($user)) !== null) {
            throw ValidationException::withMessages(['eligibility' => $reason]);
        }

        foreach ($items as $item) {
            if ($item['product']->status !== ProductStatus::Approved) {
                throw ValidationException::withMessages(['product' => $item['product']->name.' is not available.']);
            }

            if ($item['quantity'] < 1) {
                throw ValidationException::withMessages(['quantity' => 'Quantity must be at least 1.']);
            }
        }

        if ($suggestedContributionKobo !== null && $suggestedContributionKobo <= 0) {
            throw ValidationException::withMessages(['contribution' => 'Contribution must be greater than zero.']);
        }

        return DB::transaction(function () use ($user, $items, $cadence, $suggestedContributionKobo) {
            $targetTotal = 0;
            foreach ($items as $item) {
                $targetTotal += $item['product']->price_kobo * $item['quantity'];
            }

            $plan = ProductTargetPlan::query()->create([
                'user_id' => $user->id,
                'product_id' => null,
                'target_price_kobo' => $targetTotal,
                'payment_mode' => PlanPaymentMode::Schedule,
                'cadence' => $cadence,
                'suggested_contribution_kobo' => $suggestedContributionKobo,
                'amount_saved_kobo' => 0,
                'remaining_balance_kobo' => $targetTotal,
                'progress_percentage' => 0,
                'status' => PlanStatus::Active,
                'started_at' => now(),
                'expected_completion_date' => $this->projectFromSuggested($cadence, $suggestedContributionKobo, $targetTotal),
            ]);

            foreach ($items as $item) {
                PlanItem::query()->create([
                    'plan_id' => $plan->id,
                    'product_id' => $item['product']->id,
                    'vendor_id' => $item['product']->vendor_id,
                    'locked_price_kobo' => $item['product']->price_kobo,
                    'quantity' => $item['quantity'],
                    'created_at' => now(),
                ]);
            }

            $this->recordStatusEvent($plan, null, PlanStatus::Active, $user);

            $this->auditLogger->log(
                actor: $user,
                subject: $plan,
                action: 'savings.bundle_plan_created',
                newValues: [
                    'target_price_kobo' => $targetTotal,
                    'item_count' => count($items),
                    'cadence' => $cadence->value,
                ],
            );

            return $plan;
        });
    }

    /** Apply money from the wallet balance to a plan (ledger debit). */
    public function contributeFromWallet(User $user, ProductTargetPlan $plan, int $amountKobo): ProductTargetPlan
    {
        return DB::transaction(function () use ($user, $plan, $amountKobo) {
            $plan = $this->lockContributablePlan($user, $plan, $amountKobo);

            $transaction = $this->walletService->debitForSavings(
                user: $user,
                amountKobo: $amountKobo,
                type: WalletTransactionType::PlanContribution,
                reference: 'PLANC-'.Str::uuid()->toString(),
                metadata: ['plan_id' => $plan->id, 'product_id' => $plan->product_id],
            );

            return $this->applyContribution($user, $plan, $amountKobo, ContributionSource::PaystackDeposit, $transaction->id);
        });
    }

    /** Apply money from the Open Savings pot to a plan (no wallet movement). */
    public function contributeFromOpenSavings(User $user, ProductTargetPlan $plan, int $amountKobo): ProductTargetPlan
    {
        return DB::transaction(function () use ($user, $plan, $amountKobo) {
            $plan = $this->lockContributablePlan($user, $plan, $amountKobo);

            $openSaving = OpenSaving::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($openSaving === null || $openSaving->status !== WalletStatus::Active) {
                throw ValidationException::withMessages(['amount' => 'Open Savings is not active.']);
            }

            if ($openSaving->balance_kobo < $amountKobo) {
                throw ValidationException::withMessages(['amount' => 'Insufficient Open Savings balance.']);
            }

            $openSaving->forceFill(['balance_kobo' => $openSaving->balance_kobo - $amountKobo])->save();

            return $this->applyContribution($user, $plan, $amountKobo, ContributionSource::OpenSavings, null);
        });
    }

    /**
     * Shared contribution write path — also used by RedirectionService for
     * full-balance moves (source = redirection). Caller must already hold the
     * plan row lock and have debited the money's source.
     */
    public function applyContribution(
        User $user,
        ProductTargetPlan $plan,
        int $amountKobo,
        ContributionSource $source,
        ?int $walletTransactionId,
    ): ProductTargetPlan {
        PlanContribution::query()->create([
            'plan_id' => $plan->id,
            'wallet_transaction_id' => $walletTransactionId,
            'amount_kobo' => $amountKobo,
            'contribution_date' => now()->toDateString(),
            'source' => $source,
        ]);

        $plan->forceFill(['last_contribution_at' => now()]);
        $this->recalculate($plan);

        $this->auditLogger->log(
            actor: $user,
            subject: $plan,
            action: 'savings.plan_contribution',
            newValues: [
                'amount_kobo' => $amountKobo,
                'source' => $source->value,
                'amount_saved_kobo' => $plan->amount_saved_kobo,
                'progress_percentage' => $plan->progress_percentage,
            ],
        );

        return $plan;
    }

    /**
     * Recompute amount saved, remaining balance, progress and expected
     * completion date from the contribution history, moving the plan to
     * Ready for Delivery at 100%. Expected completion uses the customer's
     * actual average contribution over the last three cycles (PRD rule).
     */
    public function recalculate(ProductTargetPlan $plan): void
    {
        $saved = (int) $plan->contributions()->sum('amount_kobo');
        $remaining = max(0, $plan->target_price_kobo - $saved);
        $progress = $plan->target_price_kobo > 0
            ? min(100, round($saved / $plan->target_price_kobo * 100, 2))
            : 0;

        $plan->forceFill([
            'amount_saved_kobo' => $saved,
            'remaining_balance_kobo' => $remaining,
            'progress_percentage' => $progress,
            'expected_completion_date' => $this->projectCompletionDate($plan, $remaining),
        ]);

        if ($remaining === 0 && ! in_array($plan->status, [PlanStatus::ReadyForDelivery, PlanStatus::Completed], true)) {
            $old = $plan->status;
            $plan->forceFill([
                'status' => PlanStatus::ReadyForDelivery,
                'ready_for_delivery_at' => now(),
                'expected_completion_date' => now()->toDateString(),
            ]);
            $plan->save();
            $this->recordStatusEvent($plan, $old, PlanStatus::ReadyForDelivery, null, 'Target fully funded');

            // Only announce once the surrounding transaction has committed —
            // listeners (Sprint 6 order creation) must never see a rollback.
            DB::afterCommit(fn () => PlanReadyForDelivery::dispatch(
                $plan->id, $plan->user_id, $plan->product_id, $plan->target_price_kobo,
            ));

            return;
        }

        $plan->save();
    }

    /** Pause without unlocking money or changing the target price. */
    public function pause(User $user, ProductTargetPlan $plan, ?string $reason = null): ProductTargetPlan
    {
        if ($plan->status !== PlanStatus::Active) {
            throw ValidationException::withMessages(['plan' => 'Only an active plan can be paused.']);
        }

        $plan->forceFill(['status' => PlanStatus::Paused, 'paused_at' => now(), 'pause_reason' => $reason])->save();
        $this->recordStatusEvent($plan, PlanStatus::Active, PlanStatus::Paused, $user, $reason);
        $this->auditLogger->log(actor: $user, subject: $plan, action: 'savings.plan_paused', newValues: ['reason' => $reason]);

        return $plan;
    }

    /** Resume a paused plan. */
    public function resume(User $user, ProductTargetPlan $plan): ProductTargetPlan
    {
        if ($plan->status !== PlanStatus::Paused) {
            throw ValidationException::withMessages(['plan' => 'Only a paused plan can be resumed.']);
        }

        $plan->forceFill(['status' => PlanStatus::Active, 'paused_at' => null, 'pause_reason' => null])->save();
        $this->recordStatusEvent($plan, PlanStatus::Paused, PlanStatus::Active, $user);
        $this->auditLogger->log(actor: $user, subject: $plan, action: 'savings.plan_resumed', newValues: []);

        return $plan;
    }

    /**
     * Mark a Ready for Delivery plan Completed — called by the Orders module
     * (via this service, which owns plan state) when the customer provides a
     * delivery address and the order is created.
     */
    public function markCompleted(User $user, ProductTargetPlan $plan): ProductTargetPlan
    {
        if ($plan->status !== PlanStatus::ReadyForDelivery) {
            throw ValidationException::withMessages(['plan' => 'Only a Ready for Delivery plan can complete.']);
        }

        $plan->forceFill(['status' => PlanStatus::Completed, 'completed_at' => now()])->save();
        $this->recordStatusEvent($plan, PlanStatus::ReadyForDelivery, PlanStatus::Completed, $user, 'Order created');
        $this->auditLogger->log(actor: $user, subject: $plan, action: 'savings.plan_completed', newValues: []);

        return $plan;
    }

    /** Append a row to the plan's status history. */
    public function recordStatusEvent(
        ProductTargetPlan $plan,
        ?PlanStatus $old,
        PlanStatus $new,
        ?User $changedBy,
        ?string $reason = null,
    ): void {
        PlanStatusEvent::query()->create([
            'plan_id' => $plan->id,
            'old_status' => $old?->value,
            'new_status' => $new->value,
            'changed_by' => $changedBy?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Re-fetch the plan with a row lock and validate it can accept this
     * contribution (owned by the user, active, amount within remaining).
     */
    private function lockContributablePlan(User $user, ProductTargetPlan $plan, int $amountKobo): ProductTargetPlan
    {
        if ($amountKobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        /** @var ProductTargetPlan $locked */
        $locked = ProductTargetPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

        if ($locked->user_id !== $user->id) {
            throw ValidationException::withMessages(['plan' => 'This plan does not belong to you.']);
        }

        if ($locked->status !== PlanStatus::Active) {
            throw ValidationException::withMessages(['plan' => 'Contributions are only accepted on an active plan.']);
        }

        if ($amountKobo > $locked->remaining_balance_kobo) {
            throw ValidationException::withMessages([
                'amount' => 'Amount exceeds the remaining balance on this plan.',
            ]);
        }

        return $locked;
    }

    /**
     * PRD rule: expected completion recalculates after each contribution from
     * the actual average contribution over the customer's last three cycles.
     * Falls back to the suggested contribution before any history exists.
     */
    private function projectCompletionDate(ProductTargetPlan $plan, int $remainingKobo): ?string
    {
        if ($remainingKobo === 0) {
            return now()->toDateString();
        }

        $cadence = $plan->cadence;
        if ($cadence === null) {
            return null; // Pay At Once plans have no schedule to project.
        }

        $recent = $plan->contributions()
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('amount_kobo');

        $perCycle = $recent->isNotEmpty()
            ? (int) ceil($recent->sum() / $recent->count())
            : ($plan->suggested_contribution_kobo ?? 0);

        if ($perCycle <= 0) {
            return null;
        }

        $cycles = (int) ceil($remainingKobo / $perCycle);

        return now()->addDays($cycles * $cadence->intervalDays())->toDateString();
    }

    /** Initial projection at plan creation, before any contributions exist. */
    private function projectFromSuggested(?PlanCadence $cadence, ?int $suggestedKobo, int $targetKobo): ?string
    {
        if ($cadence === null || $suggestedKobo === null || $suggestedKobo <= 0) {
            return null;
        }

        $cycles = (int) ceil($targetKobo / $suggestedKobo);

        return now()->addDays($cycles * $cadence->intervalDays())->toDateString();
    }
}
