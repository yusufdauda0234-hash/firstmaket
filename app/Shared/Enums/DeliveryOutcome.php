<?php

namespace App\Shared\Enums;

/**
 * How one attempt at a doorstep ended.
 *
 * Recorded per attempt rather than only on the shipment because the pattern
 * is the information: three "no one home" is a customer who needs a call
 * before the van leaves, three "wrong address" is a listing or a checkout
 * form that needs fixing. A shipment that only ever records its final state
 * cannot tell you which.
 */
enum DeliveryOutcome: string
{
    case Delivered = 'delivered';
    case NoOneHome = 'no_one_home';
    case WrongAddress = 'wrong_address';
    case Refused = 'refused';
    case Unreachable = 'unreachable';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Delivered => 'Delivered',
            self::NoOneHome => 'Nobody home',
            self::WrongAddress => 'Address is wrong',
            self::Refused => 'Customer refused it',
            self::Unreachable => 'Could not reach the customer',
            self::Other => 'Something else',
        };
    }

    public function isFailure(): bool
    {
        return $this !== self::Delivered;
    }

    /** The reasons a courier can pick when a delivery did not happen. */
    public static function failures(): array
    {
        return array_filter(self::cases(), fn (self $case) => $case->isFailure());
    }
}
