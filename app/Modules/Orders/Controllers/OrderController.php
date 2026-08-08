<?php

namespace App\Modules\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Actions\StartPaystackPaymentAction;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Customer order flows (docs/FirstMaket_Implementation_Plan.md Sprint 6):
 * list orders, track the delivery chain, and confirm receipt. Orders are
 * created by checkout (CartController) or by a savings goal reaching its
 * target (SavingsGoalController) — never here.
 */
class OrderController extends Controller
{
    /**
     * One card per purchase, not per parcel.
     *
     * Internally an order is a single unit, because that is what a vendor
     * packs and a courier carries — buying three of something legitimately
     * makes three of them, and two vendors makes two sets. Listing those raw
     * meant one payment looked like several unrelated purchases.
     *
     * So the list groups by checkout session, which is exactly "one payment",
     * and is set on both the card path and a fulfilled plan. Each group shows
     * its items and one overall status; the parcels are still there
     * underneath, reachable from the group.
     */
    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->where('customer_id', $request->user()->id)
            ->with(['product:id,name,slug', 'product.images', 'vendor:id,business_name'])
            ->orderByDesc('id')
            ->get();

        $groups = $orders
            // Orders predating checkout sessions stand alone rather than
            // collapsing into one meaningless "null" group.
            ->groupBy(fn (Order $order) => $order->checkout_session_id ?? 'single-'.$order->id)
            ->map(function (Collection $group) {
                /** @var Order $first */
                $first = $group->first();

                // Distinct products, with the units bought of each — three of
                // one thing is one line saying "x3", not three lines.
                $items = $group
                    ->groupBy(fn (Order $order) => $order->product_id)
                    ->map(fn (Collection $sameProduct) => [
                        'uuid' => $sameProduct->first()->uuid,
                        'name' => $sameProduct->first()->product->name,
                        'image' => $sameProduct->first()->product->primaryImageUrl(),
                        'vendorName' => $sameProduct->first()->vendor?->business_name,
                        'quantity' => $sameProduct->count(),
                        'lineTotalKobo' => (int) $sameProduct->sum('locked_price_kobo'),
                    ])
                    ->values();

                return [
                    // Short, quotable handle for the whole purchase. There is
                    // no order-number column, so it is derived from the uuid
                    // rather than inventing a second identifier.
                    'reference' => strtoupper(substr(str_replace('-', '', $first->uuid), 0, 8)),
                    // Opening a group lands on one of its parcels; each keeps
                    // its own tracking.
                    'firstOrderUuid' => $first->uuid,
                    'placedAt' => $first->created_at->format('j M Y'),
                    'totalKobo' => (int) $group->sum('locked_price_kobo'),
                    'parcelCount' => $group->count(),
                    'vendorCount' => $group->pluck('vendor_id')->unique()->count(),
                    'items' => $items,
                    'status' => $this->groupStatus($group),
                    'orders' => $group->map(fn (Order $order) => [
                        'uuid' => $order->uuid,
                        'productName' => $order->product->name,
                        'statusLabel' => $order->status->label(),
                        'status' => $order->status->value,
                    ])->values(),
                ];
            })
            ->values();

        return Inertia::render('Orders/Index', ['groups' => $groups]);
    }

    /**
     * One label for a whole purchase.
     *
     * All parcels agreeing is the common case and reads plainly. When they
     * disagree the group is mid-flight, and the honest summary is the least
     * advanced parcel — telling someone their order is "Delivered" while one
     * box is still being packed is the failure worth avoiding.
     *
     * @param  Collection<int, Order>  $group
     * @return array{value: string, label: string, mixed: bool}
     */
    private function groupStatus(Collection $group): array
    {
        $statuses = $group->pluck('status')->unique();

        if ($statuses->count() === 1) {
            /** @var OrderStatus $only */
            $only = $statuses->first();

            return ['value' => $only->value, 'label' => $only->label(), 'mixed' => false];
        }

        // Spelled out, not OrderStatus::cases(): the enum lists the two dead
        // ends (rejected, cancelled) last, so enum order would rank a
        // rejected parcel as the most advanced one in the group.
        $progression = [
            OrderStatus::VendorRejected,
            OrderStatus::Cancelled,
            OrderStatus::Pending,
            OrderStatus::Processing,
            OrderStatus::ReadyForPickup,
            OrderStatus::Packed,
            OrderStatus::Shipped,
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
        ];

        /** @var OrderStatus $least */
        $least = $group
            ->pluck('status')
            ->sortBy(fn (OrderStatus $status) => array_search($status, $progression, true))
            ->first();

        return [
            'value' => $least->value,
            'label' => $least->label(),
            'mixed' => true,
        ];
    }

    public function show(Request $request, Order $order): Response
    {
        abort_unless($order->customer_id === $request->user()->id, 403);

        $order->load([
            'product:id,name,slug',
            'product.images',
            // The parcel carries the delivery code the customer reads out.
            'shipment',
            'statusEvents' => fn ($q) => $q->orderBy('id'),
        ]);

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
                'goodsDueKobo' => $order->shipment?->collect_on_delivery_kobo ?? 0,
                'goodsPaidAt' => $order->shipment?->goods_paid_at?->format('j M Y, g:ia'),
                'canConfirmReceipt' => $order->status === OrderStatus::Delivered && $order->delivery_confirmed_at === null,
                // The four digits the customer reads out at the door. Shown
                // only while the parcel is actually on its way: before that it
                // is noise, and afterwards it is spent (nulled on delivery).
                'deliveryCode' => $order->shipment?->status->isOpen()
                    ? $order->shipment->delivery_code
                    : null,
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

    /** Start the customer's online payment for goods due after delivery. */
    public function payGoods(
        Request $request,
        Order $order,
        StartPaystackPaymentAction $payment,
    ): SymfonyResponse {
        abort_unless($order->customer_id === $request->user()->id, 403);

        $shipment = $order->shipment;
        abort_unless($shipment !== null && $shipment->status === ShipmentStatus::Delivered, 422);
        abort_unless($shipment->goods_paid_at === null && $shipment->collect_on_delivery_kobo > 0, 422);

        $shipment->forceFill(['goods_collection_method' => 'customer_online'])->save();

        return $payment->forShipmentGoods($request->user(), $shipment);
    }
}
