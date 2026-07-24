<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\DeliveryAssignment;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryService;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\OrderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logistics workspace (docs/FirstMaket_Implementation_Plan.md Sprint 6
 * steps 5–6): pickups and assigned deliveries with the next-step action.
 * Logistics Personnel see delivery details but no catalog/pricing
 * management (they simply have no permission for those routes).
 */
class LogisticsOrderController extends Controller
{
    /** The single next logistics step from each in-chain status. */
    private const NEXT_STEP = [
        'ready_for_pickup' => OrderStatus::Packed,
        'packed' => OrderStatus::Shipped,
        'shipped' => OrderStatus::OutForDelivery,
        'out_for_delivery' => OrderStatus::Delivered,
    ];

    public function index(Request $request): Response
    {
        $assignments = DeliveryAssignment::query()
            ->where('logistics_user_id', $request->user()->id)
            ->where('status', DeliveryAssignmentStatus::Assigned)
            ->with(['order.product:id,name', 'order.vendor:id,business_name'])
            ->orderBy('assigned_at')
            ->get()
            ->map(function (DeliveryAssignment $assignment) {
                $order = $assignment->order;
                $next = self::NEXT_STEP[$order->status->value] ?? null;

                return [
                    'orderUuid' => $order->uuid,
                    'productName' => $order->product->name,
                    'vendorName' => $order->vendor->business_name,
                    'pickupFrom' => $order->vendor->business_name,
                    'deliverTo' => "{$order->lga}, {$order->state}",
                    'address' => $order->delivery_address,
                    'status' => $order->status->value,
                    'statusLabel' => $order->status->label(),
                    'nextStep' => $next?->value,
                    'nextStepLabel' => $next?->label(),
                    'assignedAt' => $assignment->assigned_at->format('j M Y'),
                ];
            });

        return Inertia::render('Logistics/Orders', ['assignments' => $assignments]);
    }

    public function updateStatus(Request $request, Order $order, DeliveryService $deliveryService): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (OrderStatus $s) => $s->value, array_values(self::NEXT_STEP)))],
        ]);

        // The submitted value is the *target* status.
        $target = OrderStatus::from($validated['status']);

        $deliveryService->updateStatus($request->user(), $order, $target);

        return back()->with('success', 'Delivery status updated — customer notified.');
    }
}
