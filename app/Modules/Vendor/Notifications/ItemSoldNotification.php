<?php

namespace App\Modules\Vendor\Notifications;

use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Vendor "item sold" alert (docs/FirstMaket_Implementation_Plan.md Sprint 6
 * step 2): product, quantity and order number only — never customer
 * identity or address. Preference-aware since Sprint 7.
 */
class ItemSoldNotification extends PreferenceAwareNotification implements ShouldQueue
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly string $productName,
        private readonly string $amountNaira,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Orders;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You made a sale — order {$this->orderNumber}")
            ->line("Great news: your product \"{$this->productName}\" just sold for {$this->amountNaira}.")
            ->line("Order number: {$this->orderNumber}")
            ->line('Sign in to your Vendor Center to confirm stock and pack the item within the preparation window. FirstMaket handles the delivery.')
            ->line('For customer privacy, buyer details are never shared with vendors.');
    }

    public function toSms(object $notifiable): string
    {
        return "FirstMaket: you sold \"{$this->productName}\" for {$this->amountNaira}. Pack it within the preparation window — see your Vendor Center.";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'You made a sale! 🎉',
            'body' => "\"{$this->productName}\" sold for {$this->amountNaira}. Confirm stock and pack it in your Vendor Center.",
            'url' => null,
        ];
    }
}
