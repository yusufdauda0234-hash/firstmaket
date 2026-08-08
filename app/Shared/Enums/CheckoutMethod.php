<?php

namespace App\Shared\Enums;

/**
 * How a shopper settles a cart at checkout.
 *
 * There is no stored balance: Card charges the full total through Paystack
 * there and then, Pay Small Small locks the price and collects it in
 * instalments, and OPay is listed but not selectable because no OPay
 * credentials or SDK exist in this codebase.
 *
 * Keep isAvailable() as the single source of truth: the checkout screen
 * greys out anything it returns false for, and CartController rejects it.
 */
enum CheckoutMethod: string
{
    case Card = 'card';
    case PaySmallSmall = 'pay_small_small';
    case PayOnDelivery = 'pay_on_delivery';
    case Opay = 'opay';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Pay now by card',
            self::PaySmallSmall => 'Pay Small Small',
            self::PayOnDelivery => 'Pay on delivery',
            self::Opay => 'OPay',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Card => 'Visa, Mastercard or Verve through Paystack. Delivered once payment clears.',
            self::PaySmallSmall => 'Lock this price and pay it off in instalments. Delivered when the last one lands.',
            self::PayOnDelivery => 'Pay the delivery fee now and the rest in cash when it reaches you.',
            self::Opay => 'Pay from your OPay wallet.',
        };
    }

    public function isAvailable(): bool
    {
        return $this !== self::Opay;
    }

    public function unavailableReason(): ?string
    {
        return $this === self::Opay ? 'Coming soon' : null;
    }
}
