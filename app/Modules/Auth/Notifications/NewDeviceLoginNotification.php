<?php

namespace App\Modules\Auth\Notifications;

use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Login alert sent when a sign-in comes from a device fingerprint the
 * account has never used before (docs/FirstMaket_Implementation_Plan.md
 * Sprint 2: email verification and login-alert events). Security category —
 * the email toggle is locked on.
 */
class NewDeviceLoginNotification extends PreferenceAwareNotification implements ShouldQueue
{
    public function __construct(
        private readonly string $ipAddress,
        private readonly string $userAgent,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Security;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New device sign-in to your FirstMarketaccount')
            ->line('Your FirstMarketaccount was just signed in to from a device we have not seen before.')
            ->line("IP address: {$this->ipAddress}")
            ->line("Device: {$this->userAgent}")
            ->line('If this was you, no action is needed. If not, change your password immediately and contact support.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'New device sign-in',
            'body' => "Your account was signed in to from a new device (IP {$this->ipAddress}). Not you? Change your password now.",
            'url' => route('account.settings', absolute: false),
        ];
    }
}
