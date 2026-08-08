<?php

namespace App\Shared\Enums;

/**
 * What a courier drives.
 *
 * A dispatcher needs it because a motorcycle cannot take a fridge — the
 * commonest dispatch mistake there is, and the one that wastes a whole trip.
 */
enum VehicleType: string
{
    case Motorcycle = 'motorcycle';
    case Car = 'car';
    case Van = 'van';
    case Truck = 'truck';
    case OnFoot = 'on_foot';

    public function label(): string
    {
        return match ($this) {
            self::Motorcycle => 'Motorcycle',
            self::Car => 'Car',
            self::Van => 'Van',
            self::Truck => 'Truck',
            self::OnFoot => 'On foot',
        };
    }

    /** Roughly what it can carry, for the dispatcher's benefit. */
    public function capacityHint(): string
    {
        return match ($this) {
            self::Motorcycle => 'Small parcels',
            self::Car => 'Boxes up to a microwave',
            self::Van => 'Furniture and appliances',
            self::Truck => 'Bulk and multiple drops',
            self::OnFoot => 'Within walking distance',
        };
    }
}
