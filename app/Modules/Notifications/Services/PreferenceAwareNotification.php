<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base class for every user-facing notification: declares a preference
 * category and resolves its channels from the user's per-category toggles
 * (docs/FirstMaket_Implementation_Plan.md Sprint 7). Subclasses implement
 * toMail(), toDatabase() (the in-app inbox payload), and optionally
 * toSms(): string.
 */
abstract class PreferenceAwareNotification extends Notification
{
    use Queueable;

    abstract public function category(): NotificationCategory;

    /** @return array{title: string, body: string, url: string|null} */
    abstract public function toDatabase(object $notifiable): array;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return app(NotificationPreferenceService::class)->channelsFor($notifiable, $this->category());
    }
}
