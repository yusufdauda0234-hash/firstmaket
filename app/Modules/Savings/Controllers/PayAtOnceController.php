<?php

namespace App\Modules\Savings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Savings\Services\PlanService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Enums\ProductStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pay At Once checkout (docs/FirstMaket_Implementation_Plan.md Sprint 5):
 * pay the full locked product price from the wallet in one step. Feels like
 * a normal purchase; internally it is a pay_at_once Product Target Plan that
 * reaches Ready for Delivery the moment it is fully paid. Wallet money is
 * the only source, so every naira here was already webhook-verified.
 */
class PayAtOnceController extends Controller
{
    public function create(Request $request, Product $product, WalletService $walletService): Response
    {
        if ($product->status !== ProductStatus::Approved) {
            abort(404);
        }

        $wallet = $walletService->getOrCreate($request->user());

        return Inertia::render('Checkout/PayAtOnce', [
            'product' => [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'slug' => $product->slug,
                'priceKobo' => $product->price_kobo,
                'image' => $product->primaryImageUrl(),
                'vendorName' => $product->vendor->business_name,
            ],
            'walletBalanceKobo' => $wallet->balance_kobo,
        ]);
    }

    public function store(Request $request, Product $product, PlanService $planService): RedirectResponse
    {
        $plan = $planService->payAtOnce($request->user(), $product);

        return redirect()
            ->route('savings.plans.show', $plan->uuid)
            ->with('success', 'Payment complete — your order is ready for delivery.');
    }
}
