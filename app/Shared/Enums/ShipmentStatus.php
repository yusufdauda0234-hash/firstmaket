<?php

namespace App\Shared\Enums;

/**
 * Where a parcel is.
 *
 * The first five mirror the order chain, because a shipment moves its orders
 * with it. The last two are the shipment's own: a delivery can fail without
 * the order being cancelled — nobody was home, so it goes back on the van —
 * and that distinction is the whole reason a shipment has a status at all
 * rather than just reading its orders'.
 */
enum ShipmentStatus: string
{
    /**
     * Waiting on the vendor. The parcel exists as a record the moment the
     * money lands, but there is nothing to collect until every unit in it has
     * been packed — a courier sent before that arrives at half a box.
     */
    case Pending = 'pending';
    case ReadyForPickup = 'ready_for_pickup';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting on the vendor',
            self::ReadyForPickup => 'Ready for pickup',
            self::Packed => 'Packed',
            self::Shipped => 'Picked up',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Failed => 'Delivery failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** The matching order status, for the statuses that have one. */
    public function orderStatus(): ?OrderStatus
    {
        return match ($this) {
            self::Pending => null,
            self::ReadyForPickup => OrderStatus::ReadyForPickup,
            self::Packed => OrderStatus::Packed,
            self::Shipped => OrderStatus::Shipped,
            self::OutForDelivery => OrderStatus::OutForDelivery,
            self::Delivered => OrderStatus::Delivered,
            // Failed is a delivery outcome, not an order outcome: the money
            // is untouched and the parcel goes back out tomorrow.
            self::Failed, self::Cancelled => null,
        };
    }

    /** The single next step a courier can take from here. */
    public function next(): ?self
    {
        return match ($this) {
            // Nothing a courier can do until the vendor has packed it.
            self::Pending => null,
            self::ReadyForPickup => self::Packed,
            self::Packed => self::Shipped,
            self::Shipped => self::OutForDelivery,
            self::OutForDelivery => self::Delivered,
            // A failed shipment goes back out for delivery, not forward.
            self::Failed => self::OutForDelivery,
            self::Delivered, self::Cancelled => null,
        };
    }

    /** Still the courier's problem. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Delivered, self::Cancelled], true);
    }

    /** Waiting for a courier to be given it. */
    public function isDispatchable(): bool
    {
        return in_array($this, [self::ReadyForPickup, self::Packed, self::Failed], true);
    }
}
