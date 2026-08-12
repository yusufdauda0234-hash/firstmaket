<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An admin broadcast, delivered to one recipient.
 *
 * Carries the announcement's own text rather than a model reference so the
 * queued payload stays self-contained: a broadcast to every customer sits on
 * the queue for a while, and re-reading a row that has since been edited
 * would deliver two different messages under one announcement.
 */
class AnnouncementNotification extends PreferenceAwareNotification implements ShouldQueue
{
    /**
     * @param  list<string>  $channels  The channels the sender asked for —
     *                                  narrowed by the recipient's own
     *                                  preferences in via().
     */
    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly array $channels,
        private readonly NotificationCategory $category,
    ) {}

    public static function for(Announcement $announcement): self
    {
        return new self(
            $announcement->title,
            $announcement->body,
            $announcement->channels,
            $announcement->category,
        );
    }

    public function category(): NotificationCategory
    {
        return $this->category;
    }

    /**
     * The intersection of what the sender chose and what this person has
     * agreed to receive.
     *
     * Deliberately an intersection, not an override: an admin can send down
     * fewer channels than someone has switched on, never more. Somebody who
     * turned off promotional SMS does not start getting it again because a
     * broadcast ticked the box. Security keeps its locked-on email, which
     * parent::via() already enforces.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_values(array_intersect(parent::via($notifiable), $this->channels));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->line($this->body);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => null,
        ];
    }

    public function toSms(object $notifiable): string
    {
        return "{$this->title}: {$this->body}";
    }
}
