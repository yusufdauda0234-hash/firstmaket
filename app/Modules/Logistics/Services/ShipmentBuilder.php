<?php

namespace App\Modules\Logistics\Services;

use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ShipmentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning a paid checkout into parcels.
 *
 * One shipment per (checkout session, vendor). Two vendors on one order
 * means two parcels, because they are picked up in two places — that is a
 * fact about the world, not a modelling choice. Two units from one vendor
 * means one parcel, for the same reason.
 *
 * Called after the orders exist rather than instead of them: the orders are
 * the money and must be written first, the shipment is how the goods travel.
 */
class ShipmentBuilder
{
    /**
     * Build the parcels for a checkout, attaching each order to its own.
     *
     * Idempotent on the orders it has already placed — an order that already
     * has a shipment is left alone, so a replayed webhook cannot split one
     * parcel into two.
     *
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, Shipment>
     */
    public function fromCheckout(CheckoutSession $session, Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        return DB::transaction(function () use ($session, $orders) {
            $shipments = collect();

            $unshipped = $orders->filter(fn (Order $order) => $order->shipment_id === null);

            // Cash owed at the door is split across the parcels in proportion
            // to what each is worth, so a two-vendor order does not put the
            // whole amount on whichever box happens to arrive first.
            $unshippedValue = (int) $unshipped->sum('locked_price_kobo');
            $owedAtDoor = (int) $session->collect_on_delivery_kobo;
            $allocated = 0;
            $lastVendorId = $unshipped->groupBy('vendor_id')->keys()->last();

            foreach ($unshipped->groupBy('vendor_id') as $vendorId => $vendorOrders) {
                /** @var Order $sample */
                $sample = $vendorOrders->first();

                // The last parcel takes the remainder, so the parts always
                // sum to exactly what the customer agreed to pay.
                $collectHere = $owedAtDoor === 0 ? 0 : ($vendorId === $lastVendorId
                    ? $owedAtDoor - $allocated
                    : (int) floor($owedAtDoor * (int) $vendorOrders->sum('locked_price_kobo') / max(1, $unshippedValue)));

                $allocated += $collectHere;

                $shipment = Shipment::query()->create([
                    'checkout_session_id' => $session->id,
                    'vendor_id' => (int) $vendorId,
                    'customer_id' => $sample->customer_id,
                    // The record exists the moment the money lands, but there
                    // is nothing to collect until the vendor has packed every
                    // unit. syncFromOrders promotes it to ReadyForPickup.
                    'status' => ShipmentStatus::Pending,
                    'delivery_address' => $sample->delivery_address,
                    'state' => $sample->state,
                    'lga' => $sample->lga,
                    'recipient_name' => $sample->recipient_name,
                    'recipient_phone' => $sample->recipient_phone,
                    'landmark' => $sample->landmark,
                    // Generated now and shown to the customer on their order
                    // page, so it is in their hands well before anyone knocks.
                    'delivery_code' => Shipment::freshCode(),
                    'collect_on_delivery_kobo' => $collectHere,
                ]);

                Order::query()
                    ->whereIn('id', $vendorOrders->pluck('id'))
                    ->update(['shipment_id' => $shipment->id]);

                $shipments->push($shipment);
            }

            return $shipments;
        });
    }

    /**
     * Keep a shipment's status honest after its orders move on their own.
     *
     * The vendor marks items ready one at a time, and an admin can cancel a
     * single unit. The parcel is ready when everything still in it is ready,
     * and cancelled when nothing is left — anything else and a courier would
     * be sent for a box that is not packed.
     */
    public function syncFromOrders(Shipment $shipment): Shipment
    {
        return DB::transaction(function () use ($shipment) {
            /** @var Shipment $shipment */
            $shipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            $live = $shipment->orders()
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::VendorRejected])
                ->get();

            if ($live->isEmpty()) {
                $shipment->forceFill(['status' => ShipmentStatus::Cancelled])->save();

                return $shipment;
            }

            // Once a courier has it, the shipment leads and the orders
            // follow — not the other way round.
            if (! in_array($shipment->status, [ShipmentStatus::Pending, ShipmentStatus::ReadyForPickup], true)) {
                return $shipment;
            }

            // Every unit packed, or already further along. Partially packed is
            // not ready: a courier sent now collects half a box.
            $allReady = $live->every(
                fn (Order $order) => ! in_array($order->status, [
                    OrderStatus::Pending, OrderStatus::Processing,
                ], true),
            );

            $shipment->forceFill([
                'status' => $allReady ? ShipmentStatus::ReadyForPickup : ShipmentStatus::Pending,
            ])->save();

            return $shipment;
        });
    }
}
