<?php

namespace App\Modules\Orders\Notifications;

use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Here is your receipt", sent once the checkout is settled.
 *
 * Carries a link rather than an attachment: the receipt page renders the
 * stored figures and prints cleanly to PDF from any browser, so there is one
 * document rather than a file in an inbox that can drift from the record.
 */
class ReceiptIssuedNotification extends PreferenceAwareNotification implements ShouldQueue
{
    public function __construct(
        private readonly string $receiptUuid,
        private readonly string $receiptNumber,
        private readonly int $totalKobo,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Orders;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your FirstMaket receipt {$this->receiptNumber}")
            ->greeting('Thank you for your order.')
            ->line("Receipt {$this->receiptNumber} for {$this->amount()} is ready.")
            ->action('View receipt', route('receipts.show', $this->receiptUuid))
            ->line('Keep this number handy if you ever need to contact support about this order.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Receipt ready',
            'body' => "Receipt {$this->receiptNumber} for {$this->amount()}.",
            'url' => route('receipts.show', $this->receiptUuid, false),
        ];
    }

    private function amount(): string
    {
        return '₦'.number_format($this->totalKobo / 100, 2);
    }
}
