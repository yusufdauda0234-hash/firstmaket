<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Orders\Models\DeliveryAssignment;
use App\Modules\Orders\Models\Order;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FirstMarket-controlled logistics (docs/firstmarket_Implementation_Plan.md
 * Sprint 6 steps 5–6): admin assigns a Logistics Personnel user; that user
 * walks the order through pickup and delivery. Every step notifies the
 * customer via OrderStatusChanged (fired inside OrderService).
 */
class DeliveryService
{
    /** Statuses a logistics user may set, in chain order. */
    private const LOGISTICS_STEPS = [
        OrderStatus::Packed,
        OrderStatus::Shipped,
        OrderStatus::OutForDelivery,
        OrderStatus::Delivered,
    ];

    public function __construct(
        private readonly OrderService $orderService,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /** Admin assigns (or reassigns) the order to a logistics user. */
    public function assign(User $admin, Order $order, User $logisticsUser): DeliveryAssignment
    {
        if (! $logisticsUser->hasRole('Logistics Personnel')) {
            throw ValidationException::withMessages(['logistics_user' => 'Pick a Logistics Personnel account.']);
        }

        if (! in_array($order->status, [OrderStatus::Processing, OrderStatus::ReadyForPickup, OrderStatus::Packed], true)) {
            throw ValidationException::withMessages(['order' => 'This order is not awaiting delivery assignment.']);
        }

        return DB::transaction(function () use ($admin, $order, $logisticsUser) {
            // A fresh assignment supersedes any previous active one.
            DeliveryAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', DeliveryAssignmentStatus::Assigned)
                ->update(['status' => DeliveryAssignmentStatus::Cancelled]);

            $assignment = DeliveryAssignment::query()->create([
                'order_id' => $order->id,
                'logistics_user_id' => $logisticsUser->id,
                'assigned_by' => $admin->id,
                'assigned_at' => now(),
                'status' => DeliveryAssignmentStatus::Assigned,
            ]);

            $this->auditLogger->log(actor: $admin, subject: $order, action: 'orders.delivery_assigned', newValues: [
                'logistics_user_id' => $logisticsUser->id,
            ]);

            return $assignment;
        });
    }

    /**
     * Logistics moves the order one step along the delivery chain. Only the
     * assigned logistics user (or an admin with orders.manage) may act.
     */
    public function updateStatus(User $actor, Order $order, OrderStatus $to, ?string $note = null): Order
    {
        if (! in_array($to, self::LOGISTICS_STEPS, true)) {
            throw ValidationException::withMessages(['status' => 'Not a logistics delivery step.']);
        }

        $isAssigned = DeliveryAssignment::query()
            ->where('order_id', $order->id)
            ->where('logistics_user_id', $actor->id)
            ->where('status', DeliveryAssignmentStatus::Assigned)
            ->exists();

        if (! $isAssigned && ! $actor->can('orders.manage')) {
            throw ValidationException::withMessages(['order' => 'You are not assigned to this delivery.']);
        }

        $order = $this->orderService->transition($actor, $order, $to, $note);

        if ($to === OrderStatus::Delivered) {
            DeliveryAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', DeliveryAssignmentStatus::Assigned)
                ->update(['status' => DeliveryAssignmentStatus::Completed]);
        }

        return $order;
    }
}
