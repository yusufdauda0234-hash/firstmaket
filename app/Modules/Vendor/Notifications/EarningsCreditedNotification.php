<?php

namespace App\Modules\Vendor\Notifications;

use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Vendor earnings credit alert (docs/FirstMaket_Implementation_Plan.md
 * Sprint 6 step 8): sent when a confirmed delivery credits the earnings
 * ledger. Preference-aware since Sprint 7.
 */
class EarningsCreditedNotification extends PreferenceAwareNotification implements ShouldQueue
{
    public function __construct(
        private readonly string $orderNumber,
        private readonly string $amountNaira,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Orders;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Earnings credited — order {$this->orderNumber}")
            ->line("Delivery for order {$this->orderNumber} is confirmed and {$this->amountNaira} has been credited to your earnings balance.")
            ->line('Cleared earnings are paid out to your verified bank account in the weekly payout run.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Earnings credited',
            'body' => "{$this->amountNaira} from order {$this->orderNumber} is now in your cleared earnings balance.",
            'url' => null,
        ];
    }
}
