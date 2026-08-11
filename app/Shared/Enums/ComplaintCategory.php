<?php

namespace App\Shared\Enums;

/**
 * What a complaint is about.
 *
 * A closed set rather than free text, because the category decides where the
 * complaint is routed and how urgently: anything touching money is escalated
 * on arrival rather than waiting its turn in the queue.
 *
 * Deliberately separate from ReturnReason. A return is "send this item back";
 * a complaint is "something went wrong and I want somebody to know". A shopper
 * whose courier was rude has no item to return, and one whose parcel never
 * arrived cannot return it either.
 */
enum ComplaintCategory: string
{
    case Delivery = 'delivery';
    case ItemNotReceived = 'item_not_received';
    case Payment = 'payment';
    case Vendor = 'vendor_conduct';
    case Product = 'product_quality';
    case Account = 'account';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery problem',
            self::ItemNotReceived => 'I never received my item',
            self::Payment => 'Payment or refund problem',
            self::Vendor => 'How a vendor behaved',
            self::Product => 'Quality of what arrived',
            self::Account => 'My account',
            self::Other => 'Something else',
        };
    }

    /**
     * Whether this jumps the queue.
     *
     * Money and undelivered goods, because both mean the customer is out of
     * pocket right now. Everything else is triaged in order.
     */
    public function isUrgent(): bool
    {
        return in_array($this, [self::Payment, self::ItemNotReceived], true);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
