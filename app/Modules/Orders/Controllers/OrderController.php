<?php

namespace App\Modules\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Shared\Enums\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer order flows (docs/firstmarket_Implementation_Plan.md Sprint 6):
 * provide the delivery address for a fully funded plan (creating the order),
 * list orders, track the delivery chain, and confirm receipt.
 */
class OrderController extends Controller
{
    /** Create the order from a Ready for Delivery plan + delivery address. */
    public function store(Request $request, OrderService $orderService): RedirectResponse
    {
        $validated = $request->validate([
            'plan_uuid' => ['required', 'string'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'state' => ['required', 'string', 'max:60'],
            'lga' => ['required', 'string', 'max:80'],
        ]);

        $plan = ProductTargetPlan::query()->where('uuid', $validated['plan_uuid'])->firstOrFail();

        $order = $orderService->createFromPlan(
            customer: $request->user(),
            plan: $plan,
            deliveryAddress: $validated['delivery_address'],
            state: $validated['state'],
            lga: $validated['lga'],
        );

        return redirect()
            ->route('orders.show', $order->uuid)
            ->with('success', 'Order placed — the vendor has been notified.');
    }

    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->where('customer_id', $request->user()->id)
            ->with(['product:id,name,slug', 'product.images'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order) => [
                'uuid' => $order->uuid,
                'productName' => $order->product->name,
                'productImage' => $order->product->primaryImageUrl(),
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'lockedPriceKobo' => $order->locked_price_kobo,
                'createdAt' => $order->created_at->format('j M Y'),
                'deliveredAt' => $order->delivered_at?->format('j M Y'),
                'confirmed' => $order->delivery_confirmed_at !== null,
            ]);

        return Inertia::render('Orders/Index', ['orders' => $orders]);
    }

    public function show(Request $request, Order $order): Response
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        $order->load(['product:id,name,slug', 'product.images', 'statusEvents' => fn ($q) => $q->orderBy('id')]);

        return Inertia::render('Orders/Show', [
            'order' => [
                'uuid' => $order->uuid,
                'productName' => $order->product->name,
                'productSlug' => $order->product->slug,
                'productImage' => $order->product->primaryImageUrl(),
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'lockedPriceKobo' => $order->locked_price_kobo,
                'deliveryAddress' => $order->delivery_address,
                'state' => $order->state,
                'lga' => $order->lga,
                'createdAt' => $order->created_at->format('j M Y'),
                'deliveredAt' => $order->delivered_at?->format('j M Y, g:ia'),
                'confirmedAt' => $order->delivery_confirmed_at?->format('j M Y, g:ia'),
                'canConfirmReceipt' => $order->status === OrderStatus::Delivered && $order->delivery_confirmed_at === null,
                'timeline' => $order->statusEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'status' => $event->new_status,
                    'label' => OrderStatus::tryFrom($event->new_status)?->label() ?? $event->new_status,
                    'note' => $event->note,
                    'at' => $event->created_at?->format('j M Y, g:ia'),
                ]),
            ],
        ]);
    }

    public function confirmReceipt(Request $request, Order $order, OrderService $orderService): RedirectResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        $orderService->confirmDelivery($request->user(), $order);

        return back()->with('success', 'Thanks for confirming — enjoy your purchase!');
    }
}
