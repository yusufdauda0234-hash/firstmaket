<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Logistics\Services\ShipmentBuilder;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\VendorPreparationEvent;
use App\Modules\Savings\Services\SavingsService;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\VendorPreparationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vendor-side preparation workflow (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6 step 4): confirm stock, mark Ready for Pickup within the SLA, or
 * reject with a reason. Rejection routes to an admin-managed resolution —
 * refund-to-savings — never cash.
 * Vendors act through their own profile; customer identity is never exposed.
 */
class PreparationService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly SavingsService $savingsService,
        private readonly PromoRedeemer $promoRedeemer,
        private readonly ShipmentBuilder $shipmentBuilder,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /** Vendor confirms stock is on hand (prep trail only, no status change). */
    public function confirmStock(User $vendorUser, Order $order): void
    {
        $vendor = $this->assertVendorOwnsOrder($vendorUser, $order);

        if ($order->status !== OrderStatus::Processing) {
            throw ValidationException::withMessages(['order' => 'Stock can only be confirmed on a processing order.']);
        }

        VendorPreparationEvent::query()->create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'status' => VendorPreparationStatus::StockConfirmed,
            'created_at' => now(),
        ]);

        $this->auditLogger->log(actor: $vendorUser, subject: $order, action: 'orders.stock_confirmed', newValues: []);
    }

    /** Vendor has packed the item: Processing → Ready for Pickup. */
    public function markReadyForPickup(User $vendorUser, Order $order): Order
    {
        $vendor = $this->assertVendorOwnsOrder($vendorUser, $order);

        $order = $this->orderService->transition($vendorUser, $order, OrderStatus::ReadyForPickup, 'Vendor packed the item');

        VendorPreparationEvent::query()->create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'status' => VendorPreparationStatus::ReadyForPickup,
            'created_at' => now(),
        ]);

        // The box is only ready when everything still in it is ready. A
        // vendor marks units one at a time, so a courier sent after the first
        // of three would arrive at a half-packed parcel.
        $this->syncShipment($order);

        return $order;
    }

    /** Vendor cannot fulfil (e.g. out of stock): Processing → Vendor Rejected. */
    public function reject(User $vendorUser, Order $order, string $reason): Order
    {
        $vendor = $this->assertVendorOwnsOrder($vendorUser, $order);

        $order = $this->orderService->transition($vendorUser, $order, OrderStatus::VendorRejected, $reason);

        VendorPreparationEvent::query()->create([
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'status' => VendorPreparationStatus::Rejected,
            'note' => $reason,
            'created_at' => now(),
        ]);

        // One less thing in the box — and if it was the only thing, the box
        // is cancelled and must come off the dispatch queue.
        $this->syncShipment($order);

        return $order;
    }

    /**
     * Admin resolution for a vendor-rejected order: cancel it and credit what
     * the customer actually paid back into their savings — money moves toward
     * another product, never out as cash. The ledger row is the compensating
     * entry, keyed to the order so a repeated resolution cannot refund twice.
     */
    public function resolveRejectionToSavings(User $admin, Order $order): Order
    {
        return DB::transaction(function () use ($admin, $order) {
            if ($order->status !== OrderStatus::VendorRejected) {
                throw ValidationException::withMessages(['order' => 'Only a vendor-rejected order can be resolved.']);
            }

            $order = $this->orderService->transition($admin, $order, OrderStatus::Cancelled, 'Refunded to customer savings');

            // Net of this unit's promo share. The locked price is what the
            // item was worth; the discount is what FirstMaket paid on the
            // customer's behalf. Refunding the gross would hand the customer
            // money they never parted with — a promo code turned into a way
            // of extracting credit, which is exactly what a marketplace with
            // no cash-out must not allow.
            $refundKobo = $order->locked_price_kobo - $order->promo_discount_kobo;

            $transaction = $this->savingsService->creditRefund(
                user: $order->customer,
                amountKobo: $refundKobo,
                reference: 'REFUND-ORDER-'.$order->uuid,
                metadata: ['order_uuid' => $order->uuid],
            );

            $this->releasePromoIfCheckoutFailedEntirely($order);

            $this->auditLogger->log(
                actor: $admin,
                subject: $order,
                action: 'orders.rejection_refunded_to_savings',
                newValues: [
                    'amount_kobo' => $refundKobo,
                    'promo_discount_kobo' => $order->promo_discount_kobo,
                    'savings_balance_kobo' => $transaction->balance_after_kobo,
                ],
            );

            return $order;
        });
    }

    /**
     * Tell the parcel its contents changed.
     *
     * Safe to call for an order with no shipment — anything raised before
     * shipments existed simply has nothing to sync.
     */
    private function syncShipment(Order $order): void
    {
        $shipment = $order->fresh()->shipment;

        if ($shipment !== null) {
            $this->shipmentBuilder->syncFromOrders($shipment);
        }
    }

    /**
     * Give a promo use back when nothing on the checkout survived.
     *
     * Deliberately all-or-nothing. Releasing on the first cancelled unit
     * would hand the code back while the customer still holds the discount
     * on everything else in the basket — one code, two discounts. Only when
     * the whole checkout has fallen through has the customer got nothing for
     * it, and only then should they be able to use it again.
     */
    private function releasePromoIfCheckoutFailedEntirely(Order $order): void
    {
        if ($order->checkout_session_id === null) {
            return;
        }

        $stillLive = Order::query()
            ->where('checkout_session_id', $order->checkout_session_id)
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::VendorRejected])
            ->exists();

        if (! $stillLive) {
            $this->promoRedeemer->release($order->checkout_session_id);
        }
    }

    /**
     * Scheduler hook: flag Processing orders past their SLA deadline exactly
     * once each, so admin sees overdue preparations. Returns rows flagged.
     */
    public function flagOverduePreparations(): int
    {
        $flagged = 0;

        Order::query()
            ->where('status', OrderStatus::Processing)
            ->where('prepare_due_at', '<', now())
            ->whereDoesntHave('preparationEvents', fn ($q) => $q->where('status', VendorPreparationStatus::SlaBreached))
            ->each(function (Order $order) use (&$flagged) {
                VendorPreparationEvent::query()->create([
                    'order_id' => $order->id,
                    'vendor_id' => $order->vendor_id,
                    'status' => VendorPreparationStatus::SlaBreached,
                    'note' => 'Preparation SLA missed',
                    'created_at' => now(),
                ]);
                $flagged++;
            });

        return $flagged;
    }

    private function assertVendorOwnsOrder(User $vendorUser, Order $order): VendorProfile
    {
        $vendor = VendorProfile::query()->where('user_id', $vendorUser->id)->first();

        if ($vendor === null || $vendor->id !== $order->vendor_id) {
            throw ValidationException::withMessages(['order' => 'This order does not belong to your store.']);
        }

        return $vendor;
    }
}
