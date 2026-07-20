<?php

namespace App\Modules\Support\Notifications;

use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Customer alert when a support agent replies to their ticket
 * (docs/firstmarket_Implementation_Plan.md Sprint 7).
 */
class TicketReplyNotification extends PreferenceAwareNotification implements ShouldQueue
{
    public function __construct(
        private readonly string $ticketUuid,
        private readonly string $subject,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Support;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Support replied: {$this->subject}")
            ->line("Our support team replied to your ticket \"{$this->subject}\".")
            ->line('Open the Support Center in your FirstMarket account to read and respond.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Support replied',
            'body' => "New reply on your ticket \"{$this->subject}\".",
            'url' => route('support.tickets.show', $this->ticketUuid, false),
        ];
    }
}
