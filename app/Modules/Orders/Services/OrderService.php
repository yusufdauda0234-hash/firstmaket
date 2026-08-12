<?php

namespace App\Modules\Orders\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Events\OrderDeliveryConfirmed;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Models\VendorPreparationEvent;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\VendorPreparationStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns every order state change (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6). Every order is created through createFromCheckoutSession —
 * whether the cart was paid in full there and then, or a savings goal
 * reached its target and SavingsGoalService cashed it in. Commission is
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
        private readonly AuditLoggerContract $auditLogger,
        private readonly ReceiptService $receipts,
    ) {}

    /**
     * Create every order for a checkout session — one order per unit,
     * possibly across several vendors — in one transaction. The delivery
     * address is already on $session, and savings has already been debited.
     *
     * `unitPriceKobo` lets a savings goal charge the price it froze when the
     * goal was created rather than today's; omit it to use the live price.
     *
     * @param  array<int, array{product: Product, quantity: int, unitPriceKobo?: int}>  $items
     * @return Collection<int, Order>
     */
    public function createFromCheckoutSession(User $customer, CheckoutSession $session, array $items): Collection
    {
        return DB::transaction(function () use ($customer, $session, $items) {
            $orders = collect();

            foreach ($items as $item) {
                $product = $item['product'];
                $unitPriceKobo = $item['unitPriceKobo'] ?? $product->price_kobo;
                // Resolved once per line, then snapshotted onto every unit:
                // a later rate change must not alter what a vendor was owed.
                $rate = CommissionRate::for($product, $unitPriceKobo);
                $commissionPerUnit = $rate->onKobo($unitPriceKobo);

                for ($unit = 0; $unit < $item['quantity']; $unit++) {
                    // This unit's share of a basket-wide promo discount.
                    // Platform-funded: it comes off the commission line, so
                    // vendor_earning_amount_kobo below is deliberately
                    // computed from the FULL price — the vendor is paid as
                    // though the customer had paid it, which is what makes a
                    // promotion something FirstMaket can run without asking
                    // every vendor's permission.
                    $promoDiscount = min(
                        $item['promoDiscountPerUnitKobo'][$unit] ?? 0,
                        $commissionPerUnit,
                    );

                    $order = Order::query()->create([
                        'checkout_session_id' => $session->id,
                        'customer_id' => $customer->id,
                        'vendor_id' => $product->vendor_id,
                        'product_id' => $product->id,
                        'delivery_address' => $session->delivery_address,
                        'state' => $session->state,
                        'lga' => $session->lga,
                        'recipient_name' => $session->recipient_name,
                        'recipient_phone' => $session->recipient_phone,
                        'landmark' => $session->landmark,
                        'status' => OrderStatus::Pending,
                        'locked_price_kobo' => $unitPriceKobo,
                        'commission_rate_percent' => number_format($rate->percent, 2, '.', ''),
                        'commission_source' => $rate->source,
                        'commission_amount_kobo' => $commissionPerUnit,
                        'promo_discount_kobo' => $promoDiscount,
                        'vendor_earning_amount_kobo' => $unitPriceKobo - $commissionPerUnit,
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

            // Issued here rather than at either call site, so a card checkout
            // and a completed savings plan produce the same document — the
            // customer paid for goods either way, and only the schedule
            // differed.
            $receipt = $this->receipts->issueFor($customer, $session, $orders);

            // Emailed after commit: this runs inside the payment webhook's
            // transaction, and a mail failure must never roll back orders the
            // customer has already been charged for.
            if ($receipt !== null) {
                DB::afterCommit(fn () => $this->receipts->email($receipt));
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

    // Rate resolution lives in CommissionRate, which also carries which rule
    // decided it so the admin order screen can explain the figure.
}
