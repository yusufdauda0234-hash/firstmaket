<?php

namespace App\Shared\Enums;

/**
 * Order fulfillment state machine (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6, modeled on Jumia's dropship flow): the marketplace controls
 * delivery end to end. Pending → admin confirms → Processing → vendor packs →
 * ReadyForPickup → logistics Packed → Shipped → OutForDelivery → Delivered.
 * VendorRejected branches to an admin-managed resolution (redirect or
 * refund-to-savings, never cash out) ending in Cancelled.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case ReadyForPickup = 'ready_for_pickup';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case VendorRejected = 'vendor_rejected';
    case Cancelled = 'cancelled';

    /** Human label used in notifications and status timelines. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Order placed',
            self::Processing => 'Confirmed — vendor preparing',
            self::ReadyForPickup => 'Ready for pickup',
            self::Packed => 'Picked up by FirstMaket',
            self::Shipped => 'Shipped',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::VendorRejected => 'Vendor could not fulfil',
            self::Cancelled => 'Cancelled',
        };
    }
}
