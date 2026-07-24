<?php

namespace App\Modules\Cart\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Savings\Services\PlanService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Contracts\PlanEligibilityContract;
use App\Shared\Enums\PlanCadence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cart CRUD + checkout (docs/FirstMaket_Implementation_Plan.md Sprint 8).
 * Checkout has two branches: pay the full cart in one wallet debit
 * (checkout()/checkoutStore(), address collected here upfront), or bundle
 * selected items into one multi-product savings plan
 * (bundlePlanSetup()/bundlePlanStore()) — a single selected item instead
 * routes into the pre-existing single-product StartPlan flow client-side.
 */
class CartController extends Controller
{
    public function index(Request $request, CartService $cartService): Response
    {
        $cart = $cartService->getOrCreate($request->user());

        $cart->load(['items.product:id,uuid,name,slug,price_kobo,stock_quantity,status', 'items.product.images', 'items.product.vendor:id,business_name']);

        return Inertia::render('Cart/Index', [
            'items' => $cart->items->map(fn (CartItem $item) => [
                'id' => $item->id,
                'productUuid' => $item->product->uuid,
                'productName' => $item->product->name,
                'productSlug' => $item->product->slug,
                'productImage' => $item->product->primaryImageUrl(),
                'vendorName' => $item->product->vendor->business_name,
                'priceKobo' => $item->product->price_kobo,
                'quantity' => $item->quantity,
                'lineTotalKobo' => $item->product->price_kobo * $item->quantity,
                'stockQuantity' => $item->product->stock_quantity,
            ]),
        ]);
    }

    public function store(Request $request, CartService $cartService): RedirectResponse
    {
        $validated = $request->validate([
            'product_uuid' => ['required', 'string'],
            'quantity' => ['integer', 'min:1'],
        ]);

        $product = Product::query()->where('uuid', $validated['product_uuid'])->firstOrFail();

        $cartService->addItem($request->user(), $product, (int) ($validated['quantity'] ?? 1));

        return back()->with('success', 'Added to cart.');
    }

    public function update(Request $request, CartItem $cartItem, CartService $cartService): RedirectResponse
    {
        $validated = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);

        $cartService->updateQuantity($request->user(), $cartItem, (int) $validated['quantity']);

        return back()->with('success', 'Cart updated.');
    }

    public function destroy(Request $request, CartItem $cartItem, CartService $cartService): RedirectResponse
    {
        $cartService->removeItem($request->user(), $cartItem);

        return back()->with('success', 'Removed from cart.');
    }

    /** Pay-in-full checkout screen: whole cart, address collected here. */
    public function checkout(Request $request, CartService $cartService, WalletService $walletService): Response|RedirectResponse
    {
        $cart = $cartService->getOrCreate($request->user());
        $cart->load(['items.product:id,uuid,name,slug,price_kobo,stock_quantity,status', 'items.product.images', 'items.product.vendor:id,business_name']);

        if ($cart->items->isEmpty()) {
            return redirect()->route('cart.index');
        }

        return Inertia::render('Cart/Checkout', [
            'items' => $cart->items->map(fn (CartItem $item) => [
                'id' => $item->id,
                'productName' => $item->product->name,
                'productImage' => $item->product->primaryImageUrl(),
                'vendorName' => $item->product->vendor->business_name,
                'quantity' => $item->quantity,
                'lineTotalKobo' => $item->product->price_kobo * $item->quantity,
            ]),
            'totalKobo' => $cart->items->sum(fn (CartItem $item) => $item->product->price_kobo * $item->quantity),
            'walletBalanceKobo' => $walletService->getOrCreate($request->user())->balance_kobo,
        ]);
    }

    public function checkoutStore(Request $request, CartService $cartService, CartCheckoutService $checkoutService): RedirectResponse
    {
        $validated = $request->validate([
            'delivery_address' => ['required', 'string', 'max:500'],
            'state' => ['required', 'string', 'max:60'],
            'lga' => ['required', 'string', 'max:80'],
        ]);

        $cart = $cartService->getOrCreate($request->user());

        $result = $checkoutService->payInFull(
            $request->user(),
            $cart,
            $validated['delivery_address'],
            $validated['state'],
            $validated['lga'],
        );

        $message = 'Order placed — every vendor has been notified.';
        if ($result['skippedProductNames'] !== []) {
            $message .= ' Note: '.implode(', ', $result['skippedProductNames']).' could not be purchased and stayed in your cart.';
        }

        return redirect()->route('orders.index')->with('success', $message);
    }

    /** Bundle-plan setup screen for two or more selected cart items. */
    public function bundlePlanSetup(Request $request, CartService $cartService, PlanEligibilityContract $eligibility): Response
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:2'],
            'items.*' => ['integer'],
        ]);

        $cart = $cartService->getOrCreate($request->user());

        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->whereIn('id', $validated['items'])
            ->with(['product.images', 'product.vendor:id,business_name'])
            ->get();

        if ($items->count() < 2) {
            abort(404);
        }

        return Inertia::render('Cart/BundlePlan', [
            'items' => $items->map(fn (CartItem $item) => [
                'id' => $item->id,
                'productName' => $item->product->name,
                'productImage' => $item->product->primaryImageUrl(),
                'vendorName' => $item->product->vendor->business_name,
                'priceKobo' => $item->product->price_kobo,
                'quantity' => $item->quantity,
            ]),
            'targetPriceKobo' => $items->sum(fn (CartItem $item) => $item->product->price_kobo * $item->quantity),
            'ineligibleReason' => $eligibility->reasonIneligible($request->user()),
        ]);
    }

    public function bundlePlanStore(Request $request, CartService $cartService, PlanService $planService): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:2'],
            'items.*' => ['integer'],
            'cadence' => ['required', Rule::enum(PlanCadence::class)],
            'contribution_naira' => ['nullable', 'numeric', 'min:100', 'max:5000000'],
        ]);

        $cart = $cartService->getOrCreate($request->user());

        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->whereIn('id', $validated['items'])
            ->with('product')
            ->get();

        if ($items->count() < 2) {
            throw ValidationException::withMessages(['items' => 'Select at least two products to bundle into one plan.']);
        }

        $plan = $planService->createMultiProduct(
            user: $request->user(),
            items: $items->map(fn (CartItem $item) => ['product' => $item->product, 'quantity' => $item->quantity])->all(),
            cadence: PlanCadence::from($validated['cadence']),
            suggestedContributionKobo: isset($validated['contribution_naira'])
                ? (int) round(((float) $validated['contribution_naira']) * 100)
                : null,
        );

        CartItem::query()->whereIn('id', $items->pluck('id'))->delete();

        return redirect()
            ->route('savings.plans.show', $plan->uuid)
            ->with('success', 'Bundle plan started — the combined price is locked in.');
    }
}
