<?php

namespace App\Modules\Orders\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Orders\Events\OrderDeliveryConfirmed;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Models\CategoryCommissionRate;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Models\VendorPreparationEvent;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Savings\Services\PlanService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PlanStatus;
use App\Shared\Enums\VendorPreparationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns every order state change (docs/firstmarket_Implementation_Plan.md
 * Sprint 6). Orders are created only from Ready for Delivery plans, with the
 * delivery address captured at that moment and commission snapshotted from
 * the category's active rate. The status machine follows the Jumia-style
 * chain; every transition is recorded in order_status_events and announced
 * via OrderStatusChanged for customer notifications.
 */
class OrderService
{
    /** Allowed transitions: current status => statuses reachable from it. */
    private const TRANSITIONS = [
        'pending' => ['processing', 'cancelled'],
        'processing' => ['ready_for_pickup', 'vendor_rejected'],
        'ready_for_pickup' => ['packed'],
        'packed' => ['shipped'],
        'shipped' => ['out_for_delivery'],
        'out_for_delivery' => ['delivered'],
        'vendor_rejected' => ['cancelled', 'processing'],
    ];

    public function __construct(
        private readonly PlanService $planService,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /**
     * Create the order from a fully funded plan the moment the customer
     * supplies a delivery address. Snapshots the price and the category's
     * active commission rate, completes the plan, and fires OrderPaid so the
     * vendor gets an "item sold" notification (never customer identity).
     */
    public function createFromPlan(
        User $customer,
        ProductTargetPlan $plan,
        string $deliveryAddress,
        string $state,
        string $lga,
    ): Order {
        return DB::transaction(function () use ($customer, $plan, $deliveryAddress, $state, $lga) {
            /** @var ProductTargetPlan $plan */
            $plan = ProductTargetPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($plan->user_id !== $customer->id) {
                throw ValidationException::withMessages(['plan' => 'This plan does not belong to you.']);
            }

            if ($plan->status !== PlanStatus::ReadyForDelivery) {
                throw ValidationException::withMessages([
                    'plan' => 'Delivery details can only be added once the plan is fully funded.',
                ]);
            }

            if (Order::query()->where('plan_id', $plan->id)->exists()) {
                throw ValidationException::withMessages(['plan' => 'An order already exists for this plan.']);
            }

            $product = $plan->product;

            // Snapshot the active commission rate — later changes never touch this order.
            $activeRate = CategoryCommissionRate::activeFor($product->category_id);
            $ratePercent = (float) ($activeRate !== null
                ? $activeRate->rate_percent
                : Setting::get('orders.default_commission_percent', 10));
            $commission = (int) round($plan->target_price_kobo * $ratePercent / 100);

            $order = Order::query()->create([
                'plan_id' => $plan->id,
                'customer_id' => $customer->id,
                'vendor_id' => $product->vendor_id,
                'product_id' => $product->id,
                'delivery_address' => $deliveryAddress,
                'state' => $state,
                'lga' => $lga,
                'status' => OrderStatus::Pending,
                'locked_price_kobo' => $plan->target_price_kobo,
                'commission_rate_percent' => number_format($ratePercent, 2, '.', ''),
                'commission_amount_kobo' => $commission,
                'vendor_earning_amount_kobo' => $plan->target_price_kobo - $commission,
                'vendor_notified_at' => now(),
            ]);

            $this->recordStatusEvent($order, null, OrderStatus::Pending, $customer, 'Order created from fully funded plan');
            $this->planService->markCompleted($customer, $plan);

            VendorPreparationEvent::query()->create([
                'order_id' => $order->id,
                'vendor_id' => $order->vendor_id,
                'status' => VendorPreparationStatus::Notified,
                'created_at' => now(),
            ]);

            $this->auditLogger->log(
                actor: $customer,
                subject: $order,
                action: 'orders.created',
                newValues: [
                    'plan_id' => $plan->id,
                    'locked_price_kobo' => $order->locked_price_kobo,
                    'commission_rate_percent' => $order->commission_rate_percent,
                ],
            );

            DB::afterCommit(fn () => OrderPaid::dispatch(
                $order->id, $order->vendor_id, $order->product_id, $order->locked_price_kobo,
            ));

            return $order;
        });
    }

    /**
     * Admin confirmation (payment/ledger check) moves Pending → Processing
     * and starts the vendor preparation SLA clock.
     */
    public function confirm(User $admin, Order $order): Order
    {
        return DB::transaction(function () use ($admin, $order) {
            $order = $this->lockOrder($order);
            $this->assertTransition($order, OrderStatus::Processing);

            $slaHours = (int) Setting::get('orders.prepare_sla_hours', 48);

            $order->forceFill([
                'status' => OrderStatus::Processing,
                'confirmed_by' => $admin->id,
                'confirmed_at' => now(),
                'prepare_due_at' => now()->addHours($slaHours),
            ])->save();

            $this->recordStatusEvent($order, OrderStatus::Pending, OrderStatus::Processing, $admin, 'Payment verified');
            $this->auditLogger->log(actor: $admin, subject: $order, action: 'orders.confirmed', newValues: [
                'prepare_due_at' => $order->prepare_due_at?->toIso8601String(),
            ]);

            return $order;
        });
    }

    /**
     * Generic guarded transition used by the vendor preparation and
     * logistics services. Records the status event and announces the change.
     */
    public function transition(?User $actor, Order $order, OrderStatus $to, ?string $note = null): Order
    {
        return DB::transaction(function () use ($actor, $order, $to, $note) {
            $order = $this->lockOrder($order);
            $this->assertTransition($order, $to);

            $from = $order->status;
            $order->forceFill(['status' => $to]);

            if ($to === OrderStatus::Delivered) {
                $order->forceFill(['delivered_at' => now()]);
            }

            $order->save();
            $this->recordStatusEvent($order, $from, $to, $actor, $note);

            $this->auditLogger->log(
                actor: $actor,
                subject: $order,
                action: 'orders.status_changed',
                newValues: ['from' => $from->value, 'to' => $to->value, 'note' => $note],
            );

            return $order;
        });
    }

    /**
     * Customer receipt confirmation (or the auto-confirm scheduler) — sets
     * delivery_confirmed_at once and fires OrderDeliveryConfirmed so the
     * Vendor module credits earnings. Idempotent: a confirmed order returns
     * unchanged.
     */
    public function confirmDelivery(?User $actor, Order $order, string $note = 'Customer confirmed receipt'): Order
    {
        return DB::transaction(function () use ($actor, $order, $note) {
            $order = $this->lockOrder($order);

            if ($order->delivery_confirmed_at !== null) {
                return $order;
            }

            if ($order->status !== OrderStatus::Delivered) {
                throw ValidationException::withMessages(['order' => 'Only a delivered order can be confirmed.']);
            }

            $order->forceFill(['delivery_confirmed_at' => now()])->save();

            $this->auditLogger->log(actor: $actor, subject: $order, action: 'orders.delivery_confirmed', newValues: [
                'note' => $note,
            ]);

            DB::afterCommit(fn () => OrderDeliveryConfirmed::dispatch(
                $order->id, $order->vendor_id, $order->vendor_earning_amount_kobo,
            ));

            return $order;
        });
    }

    /** Append to order_status_events and announce for customer notification. */
    public function recordStatusEvent(Order $order, ?OrderStatus $old, OrderStatus $new, ?User $actor, ?string $note = null): void
    {
        OrderStatusEvent::query()->create([
            'order_id' => $order->id,
            'old_status' => $old?->value,
            'new_status' => $new->value,
            'changed_by' => $actor?->id,
            'note' => $note,
            'created_at' => now(),
        ]);

        DB::afterCommit(fn () => OrderStatusChanged::dispatch(
            $order->id, $order->customer_id, $old !== null ? $old->value : '', $new->value,
        ));
    }

    private function lockOrder(Order $order): Order
    {
        /** @var Order */
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function assertTransition(Order $order, OrderStatus $to): void
    {
        $allowed = self::TRANSITIONS[$order->status->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw ValidationException::withMessages([
                'order' => "Cannot move an order from {$order->status->value} to {$to->value}.",
            ]);
        }
    }
}
