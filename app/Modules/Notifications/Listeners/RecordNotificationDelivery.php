<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Delivery-failure monitoring (docs/FirstMaket_Implementation_Plan.md
 * Sprint 7): one notification_deliveries row per attempted send, mapping
 * Laravel channels to the schema's email | sms | browser vocabulary.
 */
class RecordNotificationDelivery
{
    private const CHANNEL_MAP = [
        'mail' => 'email',
        'sms' => 'sms',
        'database' => 'browser',
    ];

    public function handleSent(NotificationSent $event): void
    {
        $this->record($event->notifiable, $event->channel, 'sent', $event->notification->id);
    }

    public function handleFailed(NotificationFailed $event): void
    {
        $error = $event->data['message'] ?? null;

        $this->record(
            $event->notifiable,
            $event->channel,
            'failed',
            $event->notification->id,
            is_string($error) ? $error : null,
        );
    }

    private function record(mixed $notifiable, string $channel, string $status, ?string $notificationId, ?string $error = null): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        NotificationDelivery::query()->create([
            'user_id' => $notifiable->id,
            'notification_id' => $notificationId,
            'channel' => self::CHANNEL_MAP[$channel] ?? $channel,
            'provider' => $channel === 'mail' ? (string) config('mail.default') : null,
            'status' => $status,
            'error_message' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
            'created_at' => now(),
        ]);
    }
}
