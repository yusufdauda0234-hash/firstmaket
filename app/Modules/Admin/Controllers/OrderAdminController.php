<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PreparationService;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin order management (docs/FirstMaket_Implementation_Plan.md Sprint 6):
 * the confirmation queue (payment check → Processing), the SLA watchlist,
 * logistics assignment, and the vendor-rejection resolution path
 * (refund-to-savings — never cash).
 */
class OrderAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status');

        $orders = Order::query()
            ->with(['product:id,name', 'vendor:id,business_name', 'customer:id,name'])
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByRaw("field(status, 'pending', 'vendor_rejected', 'processing', 'ready_for_pickup', 'packed', 'shipped', 'out_for_delivery', 'delivered', 'cancelled')")
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $order) => [
                'uuid' => $order->uuid,
                'productName' => $order->product->name,
                'vendorName' => $order->vendor->business_name,
                'customerName' => $order->customer->name,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'lockedPriceKobo' => $order->locked_price_kobo,
                'prepareOverdue' => $order->isPreparationOverdue(),
                'createdAt' => $order->created_at->format('j M Y'),
            ]);

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $orders,
            'filters' => ['status' => $status],
            'pendingCount' => Order::query()->where('status', OrderStatus::Pending)->count(),
            'rejectedCount' => Order::query()->where('status', OrderStatus::VendorRejected)->count(),
            'overdueCount' => Order::query()
                ->where('status', OrderStatus::Processing)
                ->where('prepare_due_at', '<', now())
                ->count(),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $order->load([
            'product:id,name,slug,category_id',
            'product.category:id,name',
            'vendor:id,business_name',
            'customer:id,name,email,phone',
            // The relation is savingsGoal; "plan" is only what the UI calls it.
            'savingsGoal:id,uuid',
            'statusEvents' => fn ($q) => $q->orderBy('id'),
            'preparationEvents' => fn ($q) => $q->orderBy('id'),
            'deliveryAssignments' => fn ($q) => $q->orderByDesc('id'),
            'shipment.assignments.logisticsUser:id,name',
        ]);

        $logisticsUsers = User::role('Logistics Personnel')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);

        // Read off the parcel, not the order: assignments hang off shipments
        // now, and only rows predating them carry an order_id.
        $activeAssignment = $order->shipment?->activeAssignment()
            ?? $order->deliveryAssignments->firstWhere('status', DeliveryAssignmentStatus::Assigned);

        return Inertia::render('Admin/Orders/Show', [
            'order' => [
                'uuid' => $order->uuid,
                'productName' => $order->product->name,
                'vendorName' => $order->vendor->business_name,
                'customerName' => $order->customer->name,
                'planUuid' => $order->savingsGoal?->uuid,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'lockedPriceKobo' => $order->locked_price_kobo,
                'commissionRatePercent' => $order->commission_rate_percent,
                // Why the rate is what it is. Read from the order's own
                // snapshot, not re-resolved: today's rules may differ from
                // the ones this order was priced under, and answering with
                // today's would be worse than saying nothing.
                'commissionSource' => $order->commission_source,
                'commissionReason' => match ($order->commission_source) {
                    'vendor' => 'Rate agreed with '.$order->vendor->business_name,
                    'category' => ($order->product->category?->name ?? 'Category').' category rate',
                    default => 'Platform default — no rate set for this vendor or category',
                },
                'commissionKobo' => $order->commission_amount_kobo,
                'vendorEarningKobo' => $order->vendor_earning_amount_kobo,
                'deliveryAddress' => $order->delivery_address,
                'state' => $order->state,
                'lga' => $order->lga,
                'prepareDueAt' => $order->prepare_due_at?->format('j M Y, g:ia'),
                'prepareOverdue' => $order->isPreparationOverdue(),
                'confirmedAt' => $order->confirmed_at?->format('j M Y, g:ia'),
                'deliveredAt' => $order->delivered_at?->format('j M Y, g:ia'),
                'deliveryConfirmedAt' => $order->delivery_confirmed_at?->format('j M Y, g:ia'),
                'earningsCreditedAt' => $order->earnings_credited_at?->format('j M Y, g:ia'),
                'createdAt' => $order->created_at->format('j M Y, g:ia'),
                'timeline' => $order->statusEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'status' => $event->new_status,
                    'label' => OrderStatus::tryFrom($event->new_status)?->label() ?? $event->new_status,
                    'note' => $event->note,
                    'at' => $event->created_at?->format('j M Y, g:ia'),
                ]),
                'preparation' => $order->preparationEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'status' => $event->status->value,
                    'note' => $event->note,
                    'at' => $event->created_at?->format('j M Y, g:ia'),
                ]),
                'assignedLogistics' => $activeAssignment === null ? null : [
                    'id' => $activeAssignment->logistics_user_id,
                    'name' => $activeAssignment->logisticsUser->name,
                ],
            ],
            'logisticsUsers' => $logisticsUsers,
        ]);
    }

    /**
     * Confirm several paid orders in one pass.
     *
     * Confirmation is the only bulk action offered here: it is a yes/no on an
     * order that has already been paid for, so doing twenty at once is the same
     * decision twenty times. Assigning logistics needs a person chosen per
     * order, and resolving a rejection needs a judgement about that order — both
     * stay on the individual screen.
     *
     * Each order still goes through OrderService, so the rules, audit entries
     * and customer notifications are identical to confirming one by hand.
     */
    public function bulkConfirm(Request $request, OrderService $orderService): RedirectResponse
    {
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid'],
        ], [
            'uuids.required' => 'Select at least one order first.',
            'uuids.max' => 'Up to 100 orders at a time.',
        ]);

        $orders = Order::query()
            ->whereIn('uuid', $validated['uuids'])
            ->where('status', OrderStatus::Pending)
            ->get();

        $done = 0;
        $skipped = count($validated['uuids']) - $orders->count();

        foreach ($orders as $order) {
            try {
                $orderService->confirm($request->user(), $order);
                $done++;
            } catch (\Throwable) {
                // Moved on under us, or not in a state this allows.
                $skipped++;
            }
        }

        $message = "{$done} order".($done === 1 ? '' : 's').' confirmed.';

        if ($skipped > 0) {
            $message .= " {$skipped} skipped — already confirmed or no longer awaiting it.";
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    public function confirm(Request $request, Order $order, OrderService $orderService): RedirectResponse
    {
        $orderService->confirm($request->user(), $order);

        return back()->with('success', 'Order confirmed — vendor preparation clock started.');
    }

    /**
     * Assign this order's parcel to a courier.
     *
     * The parcel, not the order: two of the same item bought together travel
     * in one box, and assigning per order put the same stop on a courier's
     * list twice. The dispatch queue is the usual way in — this stays for
     * the one-off from inside an order.
     */
    public function assignLogistics(Request $request, Order $order, DeliveryService $deliveryService): RedirectResponse
    {
        $validated = $request->validate(['logistics_user_id' => ['required', 'integer']]);

        if ($order->shipment === null) {
            return back()->with('error', 'This order has no parcel yet — the vendor has not packed it.');
        }

        $logisticsUser = User::query()->findOrFail($validated['logistics_user_id']);
        $deliveryService->assign($request->user(), $order->shipment, $logisticsUser);

        return back()->with('success', "Delivery assigned to {$logisticsUser->name}.");
    }

    public function resolveRejection(Request $request, Order $order, PreparationService $preparationService): RedirectResponse
    {
        $preparationService->resolveRejectionToSavings($request->user(), $order);

        return back()->with('success', 'Order cancelled and the full amount moved to the customer’s Open Savings.');
    }
}
