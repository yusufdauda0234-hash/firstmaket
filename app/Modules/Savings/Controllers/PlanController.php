<?php

namespace App\Modules\Savings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Savings\Services\OpenSavingsService;
use App\Modules\Savings\Services\PlanService;
use App\Modules\Savings\Services\RedirectionService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\PlanPaymentMode;
use App\Shared\Enums\ProductStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product Target Plan flows (docs/FirstMaket_Implementation_Plan.md Sprint
 * 5): start a plan from a product, track progress, contribute from wallet or
 * Open Savings, pause/resume, and redirect. All money math lives in the
 * services; controllers stay thin.
 */
class PlanController extends Controller
{
    /** Plan setup page reached from the product detail "Save Small Small" CTA. */
    public function start(Request $request, Product $product, WalletService $walletService, OpenSavingsService $openSavingsService): Response|RedirectResponse
    {
        if ($product->status !== ProductStatus::Approved) {
            abort(404);
        }

        $user = $request->user();

        return Inertia::render('Savings/StartPlan', [
            'product' => $this->productSummary($product),
            'walletBalanceKobo' => $walletService->getOrCreate($user)->balance_kobo,
            'openSavingsBalanceKobo' => $openSavingsService->getOrCreate($user)->balance_kobo,
        ]);
    }

    public function store(Request $request, PlanService $planService): RedirectResponse
    {
        $validated = $request->validate([
            'product_uuid' => ['required', 'string'],
            'cadence' => ['required', Rule::enum(PlanCadence::class)],
            'contribution_naira' => ['nullable', 'numeric', 'min:100', 'max:5000000'],
        ]);

        $product = Product::query()->where('uuid', $validated['product_uuid'])->firstOrFail();

        $plan = $planService->create(
            user: $request->user(),
            product: $product,
            mode: PlanPaymentMode::Schedule,
            cadence: PlanCadence::from($validated['cadence']),
            suggestedContributionKobo: isset($validated['contribution_naira'])
                ? (int) round(((float) $validated['contribution_naira']) * 100)
                : null,
        );

        return redirect()
            ->route('savings.plans.show', $plan->uuid)
            ->with('success', 'Plan started — the price is locked in.');
    }

    /** Product Tracker page — a bundled (multi-product) plan renders a dedicated page. */
    public function show(Request $request, ProductTargetPlan $plan): Response
    {
        abort_unless($plan->user_id === $request->user()->id, 403);

        if ($plan->isBundle()) {
            return $this->showBundle($request, $plan);
        }

        $plan->load(['product:id,uuid,name,slug,price_kobo', 'product.images', 'contributions' => fn ($q) => $q->orderByDesc('id')->limit(20)]);

        $openSaving = app(OpenSavingsService::class)->getOrCreate($request->user());
        $wallet = app(WalletService::class)->getOrCreate($request->user());

        return Inertia::render('Savings/PlanShow', [
            'plan' => [
                'uuid' => $plan->uuid,
                'productName' => $plan->product->name,
                'productSlug' => $plan->product->slug,
                'productImage' => $plan->product->primaryImageUrl(),
                'currentProductPriceKobo' => $plan->product->price_kobo,
                'targetPriceKobo' => $plan->target_price_kobo,
                'amountSavedKobo' => $plan->amount_saved_kobo,
                'remainingKobo' => $plan->remaining_balance_kobo,
                'progress' => (float) $plan->progress_percentage,
                'paymentMode' => $plan->payment_mode->value,
                'cadence' => $plan->cadence?->value,
                'suggestedContributionKobo' => $plan->suggested_contribution_kobo,
                'status' => $plan->status->value,
                'pauseReason' => $plan->pause_reason,
                'expectedCompletionDate' => $plan->expected_completion_date?->format('j M Y'),
                'startedAt' => $plan->started_at?->format('j M Y'),
                'readyForDeliveryAt' => $plan->ready_for_delivery_at?->format('j M Y'),
                'contributions' => $plan->contributions->map(fn ($contribution) => [
                    'id' => $contribution->id,
                    'amountKobo' => $contribution->amount_kobo,
                    'source' => $contribution->source->value,
                    'date' => $contribution->contribution_date->format('j M Y'),
                ]),
            ],
            'walletBalanceKobo' => $wallet->balance_kobo,
            'openSavingsBalanceKobo' => $openSaving->balance_kobo,
            // Ready-for-delivery prompt: existing order (if any) so the page
            // shows either the address form or the tracking link (Sprint 6).
            'orderUuid' => Order::query()
                ->where('plan_id', $plan->id)->value('uuid'),
        ]);
    }

    /** Product Tracker page for a Sprint 8 bundled (multi-product) plan. */
    private function showBundle(Request $request, ProductTargetPlan $plan): Response
    {
        $plan->load(['items.product:id,name,slug,price_kobo', 'items.product.images', 'items.vendor:id,business_name']);

        $openSaving = app(OpenSavingsService::class)->getOrCreate($request->user());
        $wallet = app(WalletService::class)->getOrCreate($request->user());

        $orders = Order::query()->where('plan_id', $plan->id)->get(['uuid']);

        return Inertia::render('Savings/BundlePlanShow', [
            'plan' => [
                'uuid' => $plan->uuid,
                'targetPriceKobo' => $plan->target_price_kobo,
                'amountSavedKobo' => $plan->amount_saved_kobo,
                'remainingKobo' => $plan->remaining_balance_kobo,
                'progress' => (float) $plan->progress_percentage,
                'cadence' => $plan->cadence?->value,
                'suggestedContributionKobo' => $plan->suggested_contribution_kobo,
                'status' => $plan->status->value,
                'pauseReason' => $plan->pause_reason,
                'expectedCompletionDate' => $plan->expected_completion_date?->format('j M Y'),
                'startedAt' => $plan->started_at?->format('j M Y'),
                'readyForDeliveryAt' => $plan->ready_for_delivery_at?->format('j M Y'),
                'items' => $plan->items->map(fn ($item) => [
                    'id' => $item->id,
                    'productName' => $item->product->name,
                    'productImage' => $item->product->primaryImageUrl(),
                    'vendorName' => $item->vendor->business_name,
                    'lockedPriceKobo' => $item->locked_price_kobo,
                    'quantity' => $item->quantity,
                ]),
            ],
            'walletBalanceKobo' => $wallet->balance_kobo,
            'openSavingsBalanceKobo' => $openSaving->balance_kobo,
            'orderUuids' => $orders->pluck('uuid'),
        ]);
    }

    public function contribute(Request $request, ProductTargetPlan $plan, PlanService $planService): RedirectResponse
    {
        abort_unless($plan->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'amount_naira' => ['required', 'numeric', 'min:100', 'max:5000000'],
            'source' => ['required', Rule::in(['wallet', 'open_savings'])],
        ]);

        $amountKobo = (int) round(((float) $validated['amount_naira']) * 100);

        if ($validated['source'] === 'wallet') {
            $planService->contributeFromWallet($request->user(), $plan, $amountKobo);
        } else {
            $planService->contributeFromOpenSavings($request->user(), $plan, $amountKobo);
        }

        return back()->with('success', 'Contribution applied.');
    }

    public function pause(Request $request, ProductTargetPlan $plan, PlanService $planService): RedirectResponse
    {
        abort_unless($plan->user_id === $request->user()->id, 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $planService->pause($request->user(), $plan, $validated['reason'] ?? null);

        return back()->with('success', 'Plan paused. Your money stays locked to this target.');
    }

    public function resume(Request $request, ProductTargetPlan $plan, PlanService $planService): RedirectResponse
    {
        abort_unless($plan->user_id === $request->user()->id, 403);

        $planService->resume($request->user(), $plan);

        return back()->with('success', 'Plan resumed.');
    }

    /** Move the full Open Savings balance into this plan. */
    public function redirectOpenSavings(Request $request, ProductTargetPlan $plan, RedirectionService $redirectionService): RedirectResponse
    {
        abort_unless($plan->user_id === $request->user()->id, 403);

        $redirectionService->redirectOpenSavings($request->user(), $plan);

        return back()->with('success', 'Open Savings redirected into this plan.');
    }

    /** Switch this plan to a different product, carrying the full balance. */
    public function switchProduct(Request $request, ProductTargetPlan $plan, RedirectionService $redirectionService): RedirectResponse
    {
        abort_unless($plan->user_id === $request->user()->id, 403);

        $validated = $request->validate(['product_uuid' => ['required', 'string']]);

        $newProduct = Product::query()->where('uuid', $validated['product_uuid'])->firstOrFail();

        $redirectionService->switchProduct($request->user(), $plan, $newProduct);

        return back()->with('success', 'Plan redirected — new price locked.');
    }

    /** @return array<string, mixed> */
    private function productSummary(Product $product): array
    {
        return [
            'uuid' => $product->uuid,
            'name' => $product->name,
            'slug' => $product->slug,
            'priceKobo' => $product->price_kobo,
            'image' => $product->primaryImageUrl(),
        ];
    }
}
