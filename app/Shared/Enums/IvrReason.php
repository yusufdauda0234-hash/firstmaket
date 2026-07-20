<?php

namespace App\Shared\Enums;

/**
 * IVR reason categories for hotline calls
 * (docs/firstmarket_Implementation_Plan.md Sprint 7).
 */
enum IvrReason: string
{
    case PaymentIssue = 'payment_issue';
    case DeliveryIssue = 'delivery_issue';
    case GeneralInquiry = 'general_inquiry';

    public function label(): string
    {
        return match ($this) {
            self::PaymentIssue => 'Payment issue',
            self::DeliveryIssue => 'Delivery issue',
            self::GeneralInquiry => 'General inquiry',
        };
    }
}
