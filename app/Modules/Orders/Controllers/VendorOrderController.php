<?php

namespace App\Modules\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\PreparationService;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\VendorPreparationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor Center "Orders to Prepare" (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6 step 4): sold items with the packing SLA countdown, confirm
 * stock, mark Ready for Pickup, or reject with a reason. Customer identity
 * and delivery address are NEVER serialized here — vendors only ever see
 * product, order number, price, and dates.
 */
class VendorOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $vendor = VendorProfile::query()->where('user_id', $request->user()->id)->firstOrFail();

        $orders = Order::query()
            ->where('vendor_id', $vendor->id)
            ->with(['product:id,name,slug', 'product.images', 'preparationEvents'])
            ->orderByRaw("field(status, 'processing', 'ready_for_pickup', 'pending', 'packed', 'shipped', 'out_for_delivery', 'delivered', 'vendor_rejected', 'cancelled')")
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order) => [
                'uuid' => $order->uuid,
                'productName' => $order->product->name,
                'productImage' => $order->product->primaryImageUrl(),
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                // Vendor sees their earning, not just the sticker price.
                'lockedPriceKobo' => $order->locked_price_kobo,
                'vendorEarningKobo' => $order->vendor_earning_amount_kobo,
                'prepareDueAt' => $order->prepare_due_at?->toIso8601String(),
                'prepareOverdue' => $order->isPreparationOverdue(),
                'stockConfirmed' => $order->preparationEvents
                    ->contains(fn ($event) => $event->status === VendorPreparationStatus::StockConfirmed),
                'soldAt' => $order->created_at->format('j M Y'),
                'earningsCredited' => $order->earnings_credited_at !== null,
            ]);

        return Inertia::render('Vendor/Orders/Index', [
            'orders' => $orders,
            'toPrepareCount' => $orders->where('status', OrderStatus::Processing->value)->count(),
        ]);
    }

    /**
     * Mark several orders ready for pickup.
     *
     * A vendor packing the morning's orders finishes them together, so making
     * them click through one at a time is busywork. PreparationService still
     * runs per order, and one already moved on is skipped and counted.
     */
    public function bulkReady(Request $request, PreparationService $preparationService): RedirectResponse
    {
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid'],
        ], [
            'uuids.required' => 'Select at least one order first.',
        ]);

        $vendor = $request->user()->vendorProfile;

        // Scoped to the vendor's own orders.
        $orders = Order::query()
            ->where('vendor_id', $vendor?->id)
            ->whereIn('uuid', $validated['uuids'])
            ->get();

        $done = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            try {
                $preparationService->markReadyForPickup($request->user(), $order);
                $done++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $message = "{$done} order".($done === 1 ? '' : 's').' marked ready for pickup.';

        if ($skipped > 0) {
            $message .= " {$skipped} skipped — not at that stage.";
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    public function confirmStock(Request $request, Order $order, PreparationService $preparationService): RedirectResponse
    {
        $preparationService->confirmStock($request->user(), $order);

        return back()->with('success', 'Stock confirmed.');
    }

    public function markReady(Request $request, Order $order, PreparationService $preparationService): RedirectResponse
    {
        $preparationService->markReadyForPickup($request->user(), $order);

        return back()->with('success', 'Marked ready for pickup — FirstMaket logistics is on it.');
    }

    public function reject(Request $request, Order $order, PreparationService $preparationService): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $preparationService->reject($request->user(), $order, $validated['reason']);

        return back()->with('success', 'Order rejected — FirstMaket will resolve it with the customer.');
    }
}
