<?php

namespace App\Modules\Orders\Notifications;

use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Customer delivery-chain update (docs/firstmarket_Implementation_Plan.md
 * Sprint 6, preference-aware since Sprint 7: email/SMS/in-app follow the
 * user's "Orders and delivery" toggles).
 */
class OrderStatusNotification extends PreferenceAwareNotification implements ShouldQueue
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly string $productName,
        private readonly string $statusLabel,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Orders;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order {$this->orderNumber}: {$this->statusLabel}")
            ->line("Update on your order for \"{$this->productName}\":")
            ->line("Status: {$this->statusLabel}")
            ->line('Track every step from My Orders in your FirstMarket account.');
    }

    public function toSms(object $notifiable): string
    {
        return "FirstMarket: your order for {$this->productName} is now \"{$this->statusLabel}\". Track it at firstmarket.ng/orders";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => "Order update: {$this->statusLabel}",
            'body' => "Your order for \"{$this->productName}\" is now: {$this->statusLabel}.",
            'url' => route('orders.show', $this->orderNumber, false),
        ];
    }
}
