<?php

namespace App\Modules\Savings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Savings\Services\OpenSavingsService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\PlanStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer savings dashboard (docs/firstmarket_Implementation_Plan.md Sprint
 * 5): Open Savings balance, all Product Target Plans with live progress, and
 * entry points into allocation and plan flows.
 */
class SavingsDashboardController extends Controller
{
    public function show(
        Request $request,
        OpenSavingsService $openSavingsService,
        WalletService $walletService,
    ): Response {
        $user = $request->user();
        $openSaving = $openSavingsService->getOrCreate($user);
        $wallet = $walletService->getOrCreate($user);

        $plans = ProductTargetPlan::query()
            ->where('user_id', $user->id)
            ->with(['product:id,name,slug,price_kobo', 'product.images'])
            ->orderByRaw("field(status, 'active', 'paused', 'ready_for_delivery', 'completed', 'cancelled')")
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProductTargetPlan $plan) => [
                'uuid' => $plan->uuid,
                'productName' => $plan->product->name,
                'productSlug' => $plan->product->slug,
                'productImage' => $plan->product->primaryImageUrl(),
                'targetPriceKobo' => $plan->target_price_kobo,
                'amountSavedKobo' => $plan->amount_saved_kobo,
                'remainingKobo' => $plan->remaining_balance_kobo,
                'progress' => (float) $plan->progress_percentage,
                'paymentMode' => $plan->payment_mode->value,
                'cadence' => $plan->cadence?->value,
                'status' => $plan->status->value,
                'expectedCompletionDate' => $plan->expected_completion_date?->format('j M Y'),
                'startedAt' => $plan->started_at?->format('j M Y'),
            ]);

        return Inertia::render('Savings/Index', [
            'openSavingsBalanceKobo' => $openSaving->balance_kobo,
            'walletBalanceKobo' => $wallet->balance_kobo,
            'plans' => $plans,
            'activePlanCount' => $plans->where('status', PlanStatus::Active->value)->count(),
            'identityVerified' => $user->customerProfile?->canActivateTargetPlans() ?? false,
        ]);
    }
}
