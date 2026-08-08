<?php

namespace App\Modules\Logistics\Services;

use App\Models\User;
use App\Modules\Logistics\Models\DeliveryAssignment;
use App\Modules\Logistics\Models\DeliveryAttempt;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\DeliveryAssignmentStatus;
use App\Shared\Enums\DeliveryOutcome;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FirstMaket-controlled logistics: a dispatcher gives a parcel to a courier,
 * the courier walks it to the door, and either hands it over against a code
 * or records why they could not.
 *
 * Everything here operates on shipments. Orders move because their shipment
 * moved, in the same transaction — a customer whose tracking page disagreed
 * with the courier's app would be right to distrust both.
 *
 * Most orders are prepaid through Paystack, but pay-on-delivery ones are not:
 * the courier takes cash at the door. That is recorded here, inside the same
 * transaction that closes the delivery, so a handed-over parcel can never
 * exist without a row saying who is holding the money for it.
 */
class DeliveryService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly CourierCashService $cash,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /** Give a parcel to a courier, taking it off whoever had it before. */
    public function assign(User $dispatcher, Shipment $shipment, User $courier): DeliveryAssignment
    {
        if (! $courier->hasRole('Logistics Personnel')) {
            throw ValidationException::withMessages([
                'logistics_user' => 'Pick a Logistics Personnel account.',
            ]);
        }

        if (! $shipment->status->isDispatchable()) {
            throw ValidationException::withMessages([
                'shipment' => 'This parcel is '.strtolower($shipment->status->label())
                    .' and is not waiting for a courier.',
            ]);
        }

        return DB::transaction(function () use ($dispatcher, $shipment, $courier) {
            // A fresh assignment supersedes any live one. Cancelled, not
            // deleted: who was carrying it and when it was taken off them is
            // the first question asked when a parcel goes missing.
            DeliveryAssignment::query()
                ->where('shipment_id', $shipment->id)
                ->where('status', DeliveryAssignmentStatus::Assigned)
                ->update(['status' => DeliveryAssignmentStatus::Cancelled]);

            $assignment = DeliveryAssignment::query()->create([
                'shipment_id' => $shipment->id,
                'logistics_user_id' => $courier->id,
                'assigned_by' => $dispatcher->id,
                'assigned_at' => now(),
                'status' => DeliveryAssignmentStatus::Assigned,
            ]);

            $this->auditLogger->log(
                actor: $dispatcher,
                subject: $shipment,
                action: 'logistics.shipment_assigned',
                newValues: ['courier_id' => $courier->id, 'courier' => $courier->name],
            );

            return $assignment;
        });
    }

    /**
     * Move a parcel one step along, and its orders with it.
     *
     * Delivered is not reachable here — it needs the customer's code, which
     * is what `deliver()` is for. Letting a courier set it from the same
     * button as every other step is exactly the gap the code exists to close.
     */
    public function advance(User $actor, Shipment $shipment, ShipmentStatus $to, ?string $note = null): Shipment
    {
        if ($to === ShipmentStatus::Delivered) {
            throw ValidationException::withMessages([
                'status' => 'Handing a parcel over needs the customer’s delivery code.',
            ]);
        }

        if ($shipment->status->next() !== $to) {
            throw ValidationException::withMessages([
                'status' => 'A parcel that is '.strtolower($shipment->status->label())
                    .' cannot move to '.strtolower($to->label()).'.',
            ]);
        }

        $this->assertMayHandle($actor, $shipment);

        return DB::transaction(function () use ($actor, $shipment, $to, $note) {
            /** @var Shipment $shipment */
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            $this->moveOrders($actor, $shipment, $to, $note);

            $shipment->forceFill([
                'status' => $to,
                'dispatched_at' => $to === ShipmentStatus::OutForDelivery
                    ? ($shipment->dispatched_at ?? now())
                    : $shipment->dispatched_at,
            ])->save();

            return $shipment;
        });
    }

    /**
     * Hand the parcel over, against the code the customer reads out.
     *
     * This is the only route to Delivered, and it matters more than it looks:
     * AutoConfirmDeliveredOrders turns Delivered into released vendor
     * earnings on a timer, so before the code existed that whole chain
     * started with one unverified tap by the person holding the box.
     */
    public function deliver(User $courier, Shipment $shipment, string $code, string $collectionMethod = 'cash'): Shipment
    {
        // Checked before the permission gate, deliberately. Handing over
        // completes the assignment, so a courier who double-taps on a phone
        // is no longer carrying the parcel they just delivered — and telling
        // them "you are not carrying this" for a successful delivery is both
        // wrong and alarming.
        if ($shipment->status === ShipmentStatus::Delivered) {
            return $shipment;
        }

        $this->assertMayHandle($courier, $shipment);

        if (! in_array($shipment->status, [ShipmentStatus::OutForDelivery, ShipmentStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'shipment' => 'Mark the parcel out for delivery before handing it over.',
            ]);
        }

        // hash_equals rather than ===: a four-digit code is small enough that
        // a timing oracle is a real way to walk it, and the call costs
        // nothing.
        if ($shipment->delivery_code === null
            || ! hash_equals($shipment->delivery_code, trim($code))) {
            throw ValidationException::withMessages([
                'delivery_code' => 'That code does not match. Ask the customer to read it from their order page.',
            ]);
        }

        $this->settleGoodsBalance($shipment, $collectionMethod);

        return $this->closeAsDelivered($courier, $shipment, overriddenBy: null);
    }

    /**
     * Record how the goods balance was settled, before the parcel closes.
     *
     * Shared by the courier handover and the admin override so the two cannot
     * drift: whoever closes a pay-on-delivery parcel, the same rules decide
     * whether a courier ends up holding money for it.
     *
     * An already-settled balance is never touched. A customer who paid online
     * and then also handed the courier cash used to have the online payment
     * overwritten with `cash`, which then credited the courier's balance for
     * money FirstMaket had already been paid — the shopper charged twice, and
     * only the cash leg left in the record.
     */
    private function settleGoodsBalance(Shipment $shipment, string $collectionMethod): void
    {
        if ($shipment->collect_on_delivery_kobo <= 0 || $shipment->goods_paid_at !== null) {
            return;
        }

        if ($collectionMethod === 'courier_online') {
            throw ValidationException::withMessages([
                'collection_method' => 'The courier payment must be confirmed before handover.',
            ]);
        }

        if ($collectionMethod !== 'cash') {
            $shipment->forceFill(['goods_collection_method' => $collectionMethod])->save();

            return;
        }

        $shipment->forceFill([
            'goods_collection_method' => 'cash',
            'goods_paid_at' => now(),
            'goods_paid_by' => $shipment->customer_id,
        ])->save();
    }

    /**
     * Admin closes a delivery without the code.
     *
     * Has to exist: a customer who deleted the email still needs their
     * parcel, and a courier standing at a door cannot be the one told no.
     * Audit-logged and stamped on the shipment, so "delivered without proof"
     * is always answerable rather than indistinguishable.
     */
    public function deliverWithoutCode(
        User $admin,
        Shipment $shipment,
        string $reason,
        string $collectionMethod = 'cash',
    ): Shipment {
        if (! $admin->can('orders.manage')) {
            throw ValidationException::withMessages([
                'shipment' => 'Only an order manager can close a delivery without the code.',
            ]);
        }

        /*
         * The settlement is decided here rather than left to the default.
         *
         * Closing a pay-on-delivery parcel makes somebody responsible for the
         * money, and this path used to fall through to `cash` without saying
         * so — which put the balance on the courier's ledger as a side effect
         * of an admin action they took no part in. It still defaults to cash,
         * because that is what an override usually means, but it is now an
         * answer rather than an assumption, and it is on the audit record.
         */
        $this->settleGoodsBalance($shipment, $collectionMethod);

        $this->auditLogger->log(
            actor: $admin,
            subject: $shipment,
            action: 'logistics.delivered_without_code',
            newValues: ['reason' => $reason, 'collection_method' => $collectionMethod],
        );

        return $this->closeAsDelivered($admin, $shipment, overriddenBy: $admin);
    }

    /**
     * The courier got there and could not hand it over.
     *
     * The shipment goes back to the dispatch queue rather than forward or
     * away — the goods are fine and the customer has paid. After
     * Shipment::MAX_ATTEMPTS it stops going back out: a fourth trip to an
     * address that has refused three times is not a delivery problem, and
     * somebody has to look at it.
     */
    public function recordFailure(
        User $courier,
        Shipment $shipment,
        DeliveryOutcome $outcome,
        ?string $note = null,
    ): Shipment {
        if (! $outcome->isFailure()) {
            throw ValidationException::withMessages(['outcome' => 'That is not a failure reason.']);
        }

        $this->assertMayHandle($courier, $shipment);

        if (! $shipment->status->isOpen()) {
            throw ValidationException::withMessages([
                'shipment' => 'This parcel is already '.strtolower($shipment->status->label()).'.',
            ]);
        }

        return DB::transaction(function () use ($courier, $shipment, $outcome, $note) {
            /** @var Shipment $shipment */
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            $attemptNo = $shipment->attempt_count + 1;

            DeliveryAttempt::query()->create([
                'shipment_id' => $shipment->id,
                'courier_user_id' => $courier->id,
                'attempt_no' => $attemptNo,
                'outcome' => $outcome,
                'note' => $note,
                'created_at' => now(),
            ]);

            $shipment->forceFill([
                'status' => ShipmentStatus::Failed,
                'attempt_count' => $attemptNo,
            ])->save();

            // The run happened and is counted as work; the courier is simply
            // no longer holding the parcel.
            DeliveryAssignment::query()
                ->where('shipment_id', $shipment->id)
                ->where('status', DeliveryAssignmentStatus::Assigned)
                ->update(['status' => DeliveryAssignmentStatus::Failed]);

            $this->auditLogger->log(
                actor: $courier,
                subject: $shipment,
                action: 'logistics.delivery_failed',
                newValues: [
                    'attempt_no' => $attemptNo,
                    'outcome' => $outcome->value,
                    'exhausted' => $shipment->isExhausted(),
                ],
            );

            return $shipment;
        });
    }

    /** Shared close-out for both routes to Delivered. */
    private function closeAsDelivered(User $actor, Shipment $shipment, ?User $overriddenBy): Shipment
    {
        return DB::transaction(function () use ($actor, $shipment, $overriddenBy) {
            /** @var Shipment $shipment */
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($shipment->status === ShipmentStatus::Delivered) {
                return $shipment;
            }

            // A failed parcel never reached Out for delivery in the order
            // chain's eyes, so walk the orders through whatever they still
            // owe before Delivered.
            $this->moveOrders($actor, $shipment, ShipmentStatus::Delivered, 'Handed to the customer');

            $attemptNo = $shipment->attempt_count + 1;

            DeliveryAttempt::query()->create([
                'shipment_id' => $shipment->id,
                'courier_user_id' => $actor->id,
                'attempt_no' => $attemptNo,
                'outcome' => DeliveryOutcome::Delivered,
                'note' => $overriddenBy !== null ? 'Closed by admin without the code' : null,
                'created_at' => now(),
            ]);

            /*
             * Cash taken at the door, in this same transaction. A delivered
             * pay-on-delivery parcel must never be able to exist without a
             * row saying who is holding the money — that is the whole basis
             * on which couriers are trusted with it.
             *
             * Against the courier carrying it, not whoever closed the parcel.
             * On an admin override the actor is the admin, so the balance
             * landed on an office account while the courier — who actually
             * has the notes — showed as owing nothing.
             */
            if ($shipment->goods_collection_method === 'cash') {
                $this->cash->recordCollection($this->carrierOf($shipment) ?? $actor, $shipment);
            }

            // Cash is settled before this transaction reaches here. Customer
            // online may remain unpaid after delivery, so do not mark its
            // orders paid until the verified Paystack webhook arrives.
            if ($shipment->goods_paid_at !== null) {
                $shipment->orders()
                    ->whereNull('goods_paid_at')
                    ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::VendorRejected])
                    ->update(['goods_paid_at' => $shipment->goods_paid_at]);
            }

            $shipment->forceFill([
                'status' => ShipmentStatus::Delivered,
                'attempt_count' => $attemptNo,
                'delivered_at' => now(),
                'delivered_by' => $actor->id,
                'proof_overridden_by' => $overriddenBy?->id,
                // Spent. Leaving it readable would let the same code close a
                // later parcel to the same customer.
                'delivery_code' => null,
            ])->save();

            DeliveryAssignment::query()
                ->where('shipment_id', $shipment->id)
                ->where('status', DeliveryAssignmentStatus::Assigned)
                ->update(['status' => DeliveryAssignmentStatus::Completed]);

            return $shipment;
        });
    }

    /**
     * Walk every live order in the parcel up to where the parcel now is.
     *
     * Steps one at a time rather than jumping, because OrderService owns the
     * transition rules and fires the customer notification for each — a
     * customer who never gets "out for delivery" and then suddenly gets
     * "delivered" has been told less than the chain promises.
     */
    private function moveOrders(User $actor, Shipment $shipment, ShipmentStatus $to, ?string $note): void
    {
        $target = $to->orderStatus();

        if ($target === null) {
            return;
        }

        $chain = [
            OrderStatus::ReadyForPickup,
            OrderStatus::Packed,
            OrderStatus::Shipped,
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
        ];

        $targetIndex = array_search($target, $chain, true);

        $orders = $shipment->orders()
            ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::VendorRejected])
            ->get();

        foreach ($orders as $order) {
            $currentIndex = array_search($order->status, $chain, true);

            // An order behind the chain entirely (still Pending or
            // Processing) is not this service's to drag forward — the vendor
            // has not finished with it.
            if ($currentIndex === false || $currentIndex >= $targetIndex) {
                continue;
            }

            for ($step = $currentIndex + 1; $step <= $targetIndex; $step++) {
                $order = $this->orderService->transition($actor, $order, $chain[$step], $note);
            }
        }
    }

    /** Only the courier holding it, or somebody who manages orders. */
    /**
     * The courier currently carrying this parcel, if one is assigned.
     *
     * Null for a parcel closed before anyone picked it up, in which case the
     * caller falls back to whoever acted — there is nobody better to name.
     */
    private function carrierOf(Shipment $shipment): ?User
    {
        $assignment = DeliveryAssignment::query()
            ->where('shipment_id', $shipment->id)
            ->where('status', DeliveryAssignmentStatus::Assigned)
            ->latest('id')
            ->first();

        return $assignment?->logisticsUser;
    }

    private function assertMayHandle(User $actor, Shipment $shipment): void
    {
        $holdsIt = DeliveryAssignment::query()
            ->where('shipment_id', $shipment->id)
            ->where('logistics_user_id', $actor->id)
            ->where('status', DeliveryAssignmentStatus::Assigned)
            ->exists();

        if (! $holdsIt && ! $actor->can('orders.manage')) {
            throw ValidationException::withMessages([
                'shipment' => 'You are not carrying this parcel.',
            ]);
        }
    }

    /**
     * @deprecated Kept so any caller still passing an order keeps working;
     *             new code should go through the shipment.
     */
    public function updateStatus(User $actor, Order $order, OrderStatus $to, ?string $note = null): Order
    {
        $shipment = $order->shipment;

        if ($shipment === null) {
            throw ValidationException::withMessages([
                'order' => 'This order has no parcel to move.',
            ]);
        }

        $this->advance($actor, $shipment, ShipmentStatus::from($to->value), $note);

        return $order->fresh();
    }
}
