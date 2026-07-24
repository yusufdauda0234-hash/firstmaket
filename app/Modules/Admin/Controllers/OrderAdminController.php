<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryService;
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
            'product:id,name,slug',
            'vendor:id,business_name',
            'customer:id,name,email,phone',
            'plan:id,uuid',
            'statusEvents' => fn ($q) => $q->orderBy('id'),
            'preparationEvents' => fn ($q) => $q->orderBy('id'),
            'deliveryAssignments' => fn ($q) => $q->orderByDesc('id'),
        ]);

        $logisticsUsers = User::role('Logistics Personnel')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);

        $activeAssignment = $order->deliveryAssignments
            ->firstWhere('status', DeliveryAssignmentStatus::Assigned);

        return Inertia::render('Admin/Orders/Show', [
            'order' => [
                'uuid' => $order->uuid,
                'productName' => $order->product->name,
                'vendorName' => $order->vendor->business_name,
                'customerName' => $order->customer->name,
                'planUuid' => $order->plan?->uuid,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'lockedPriceKobo' => $order->locked_price_kobo,
                'commissionRatePercent' => $order->commission_rate_percent,
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

    public function confirm(Request $request, Order $order, OrderService $orderService): RedirectResponse
    {
        $orderService->confirm($request->user(), $order);

        return back()->with('success', 'Order confirmed — vendor preparation clock started.');
    }

    public function assignLogistics(Request $request, Order $order, DeliveryService $deliveryService): RedirectResponse
    {
        $validated = $request->validate(['logistics_user_id' => ['required', 'integer']]);

        $logisticsUser = User::query()->findOrFail($validated['logistics_user_id']);
        $deliveryService->assign($request->user(), $order, $logisticsUser);

        return back()->with('success', "Delivery assigned to {$logisticsUser->name}.");
    }

    public function resolveRejection(Request $request, Order $order, PreparationService $preparationService): RedirectResponse
    {
        $preparationService->resolveRejectionToSavings($request->user(), $order);

        return back()->with('success', 'Order cancelled and the full amount moved to the customer’s Open Savings.');
    }
}
