<?php

namespace App\Modules\Orders\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Events\OrderDeliveryConfirmed;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Models\CategoryCommissionRate;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Models\VendorPreparationEvent;
use App\Modules\Savings\Models\PlanItem;
use App\Modules\Savings\Models\ProductTargetPlan;
use App\Modules\Savings\Services\PlanService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PlanStatus;
use App\Shared\Enums\VendorPreparationStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owns every order state change (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6, extended Sprint 8). Orders are created from a Ready for Delivery
 * plan (single- or, since Sprint 8, multi-product bundle) with the delivery
 * address captured once fully funded, or — since Sprint 8 — directly from a
 * cart pay-in-full checkout with the address captured upfront. Commission is
 * always snapshotted from the category's active rate at creation. The
 * status machine follows the Jumia-style chain; every transition is recorded
 * in order_status_events and announced via OrderStatusChanged for customer
 * notifications.
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
     * Create the order from a fully funded single-product plan the moment
     * the customer supplies a delivery address. Snapshots the price and the
     * category's active commission rate, completes the plan, and fires
     * OrderPaid so the vendor gets an "item sold" notification (never
     * customer identity). Bundled multi-product plans use
     * createFromBundledPlan() instead.
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

            if ($plan->isBundle()) {
                throw ValidationException::withMessages(['plan' => 'This plan bundles multiple products — use the bundled-plan checkout instead.']);
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
     * Sprint 8: create every order for a fully funded bundled (multi-product)
     * plan the moment the customer supplies a delivery address — one order
     * per unit across every plan_item, all in one transaction, so a bundle
     * never delivers a subset of its products early. All resulting orders
     * share plan_id and a fresh plan_delivery_group_id for grouped display.
     *
     * @return Collection<int, Order>
     */
    public function createFromBundledPlan(
        User $customer,
        ProductTargetPlan $plan,
        string $deliveryAddress,
        string $state,
        string $lga,
    ): Collection {
        return DB::transaction(function () use ($customer, $plan, $deliveryAddress, $state, $lga) {
            /** @var ProductTargetPlan $plan */
            $plan = ProductTargetPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($plan->user_id !== $customer->id) {
                throw ValidationException::withMessages(['plan' => 'This plan does not belong to you.']);
            }

            if (! $plan->isBundle()) {
                throw ValidationException::withMessages(['plan' => 'This plan is not a bundled plan.']);
            }

            if ($plan->status !== PlanStatus::ReadyForDelivery) {
                throw ValidationException::withMessages([
                    'plan' => 'Delivery details can only be added once the plan is fully funded.',
                ]);
            }

            if (Order::query()->where('plan_id', $plan->id)->exists()) {
                throw ValidationException::withMessages(['plan' => 'Orders already exist for this plan.']);
            }

            $items = PlanItem::query()->where('plan_id', $plan->id)->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['plan' => 'This plan has no bundled products.']);
            }

            $groupId = (string) Str::uuid();
            $orders = collect();

            foreach ($items as $item) {
                [$ratePercent, $commissionPerUnit] = $this->commissionFor($item->product->category_id, $item->locked_price_kobo);

                for ($unit = 0; $unit < $item->quantity; $unit++) {
                    $order = Order::query()->create([
                        'plan_id' => $plan->id,
                        'plan_item_id' => $item->id,
                        'plan_delivery_group_id' => $groupId,
                        'customer_id' => $customer->id,
                        'vendor_id' => $item->vendor_id,
                        'product_id' => $item->product_id,
                        'delivery_address' => $deliveryAddress,
                        'state' => $state,
                        'lga' => $lga,
                        'status' => OrderStatus::Pending,
                        'locked_price_kobo' => $item->locked_price_kobo,
                        'commission_rate_percent' => number_format($ratePercent, 2, '.', ''),
                        'commission_amount_kobo' => $commissionPerUnit,
                        'vendor_earning_amount_kobo' => $item->locked_price_kobo - $commissionPerUnit,
                        'vendor_notified_at' => now(),
                    ]);

                    $this->recordStatusEvent($order, null, OrderStatus::Pending, $customer, 'Order created from bundled plan');

                    VendorPreparationEvent::query()->create([
                        'order_id' => $order->id,
                        'vendor_id' => $order->vendor_id,
                        'status' => VendorPreparationStatus::Notified,
                        'created_at' => now(),
                    ]);

                    DB::afterCommit(fn () => OrderPaid::dispatch(
                        $order->id, $order->vendor_id, $order->product_id, $order->locked_price_kobo,
                    ));

                    $orders->push($order);
                }
            }

            $this->planService->markCompleted($customer, $plan);

            $this->auditLogger->log(
                actor: $customer,
                subject: $plan,
                action: 'orders.bundle_created',
                newValues: ['plan_id' => $plan->id, 'order_count' => $orders->count()],
            );

            return $orders;
        });
    }

    /**
     * Sprint 8: create every order for a cart pay-in-full checkout — one
     * order per unit, possibly across several vendors — in one transaction.
     * The delivery address is already on $session (captured upfront on the
     * checkout screen, before the wallet was debited); no plan is involved.
     *
     * @param  array<int, array{product: Product, quantity: int}>  $items
     * @return Collection<int, Order>
     */
    public function createFromCheckoutSession(User $customer, CheckoutSession $session, array $items): Collection
    {
        return DB::transaction(function () use ($customer, $session, $items) {
            $orders = collect();

            foreach ($items as $item) {
                $product = $item['product'];
                [$ratePercent, $commissionPerUnit] = $this->commissionFor($product->category_id, $product->price_kobo);

                for ($unit = 0; $unit < $item['quantity']; $unit++) {
                    $order = Order::query()->create([
                        'checkout_session_id' => $session->id,
                        'customer_id' => $customer->id,
                        'vendor_id' => $product->vendor_id,
                        'product_id' => $product->id,
                        'delivery_address' => $session->delivery_address,
                        'state' => $session->state,
                        'lga' => $session->lga,
                        'status' => OrderStatus::Pending,
                        'locked_price_kobo' => $product->price_kobo,
                        'commission_rate_percent' => number_format($ratePercent, 2, '.', ''),
                        'commission_amount_kobo' => $commissionPerUnit,
                        'vendor_earning_amount_kobo' => $product->price_kobo - $commissionPerUnit,
                        'vendor_notified_at' => now(),
                    ]);

                    $this->recordStatusEvent($order, null, OrderStatus::Pending, $customer, 'Order created from cart checkout');

                    VendorPreparationEvent::query()->create([
                        'order_id' => $order->id,
                        'vendor_id' => $order->vendor_id,
                        'status' => VendorPreparationStatus::Notified,
                        'created_at' => now(),
                    ]);

                    DB::afterCommit(fn () => OrderPaid::dispatch(
                        $order->id, $order->vendor_id, $order->product_id, $order->locked_price_kobo,
                    ));

                    $orders->push($order);
                }
            }

            $this->auditLogger->log(
                actor: $customer,
                subject: $session,
                action: 'orders.cart_checkout_created',
                newValues: ['checkout_session_id' => $session->id, 'order_count' => $orders->count()],
            );

            return $orders;
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

    /**
     * Snapshot the category's active commission rate against one unit's
     * price. Shared by every order-creation path so the rule stays in one
     * place. @return array{0: float, 1: int} [ratePercent, commissionKobo]
     */
    private function commissionFor(int $categoryId, int $unitPriceKobo): array
    {
        $activeRate = CategoryCommissionRate::activeFor($categoryId);
        $ratePercent = (float) ($activeRate !== null
            ? $activeRate->rate_percent
            : Setting::get('orders.default_commission_percent', 10));

        return [$ratePercent, (int) round($unitPriceKobo * $ratePercent / 100)];
    }
}
