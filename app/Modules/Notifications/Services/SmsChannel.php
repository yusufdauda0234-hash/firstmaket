<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Shared\Contracts\SmsSenderContract;
use Illuminate\Notifications\Notification;

/**
 * Custom Laravel notification channel that delivers through the platform's
 * provider-agnostic SMS contract (docs/firstmarket_Implementation_Plan.md
 * Sprint 7: notification dispatcher for email, SMS, and browser).
 * Notifications opt in by implementing toSms(): string.
 */
class SmsChannel
{
    public function __construct(private readonly SmsSenderContract $smsSender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || $notifiable->phone === null) {
            return;
        }

        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $message = $notification->toSms($notifiable);

        if (is_string($message) && $message !== '') {
            $this->smsSender->send($notifiable->phone, $message);
        }
    }
}
