<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Jobs\SendAnnouncementJob;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Notifications\AnnouncementNotification;
use App\Shared\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Composing, counting and delivering admin broadcasts.
 */
class AnnouncementService
{
    /**
     * Everyone this announcement will reach.
     *
     * Suspended and banned accounts are excluded everywhere: they cannot sign
     * in to read an in-app message, and mailing somebody the platform has
     * shut out invites a complaint rather than achieving anything. Pending
     * verification is kept — those are real people mid-signup.
     *
     * @return Builder<User>
     */
    public function recipients(Announcement $announcement): Builder
    {
        $query = User::query()->whereNotIn('status', [UserStatus::Suspended, UserStatus::Banned]);

        return match ($announcement->audience) {
            Announcement::AUDIENCE_USER => $query->whereKey($announcement->user_id),
            Announcement::AUDIENCE_ROLE => $query->whereHas(
                'roles',
                fn (Builder $roles) => $roles->whereKey($announcement->role_id),
            ),
            default => $query,
        };
    }

    /**
     * Save the announcement and hand delivery to the queue.
     *
     * The count is resolved and stored here, before anything is sent, so the
     * sent list reports who it went to at the moment of sending rather than
     * whoever happens to hold the role today.
     */
    public function send(Announcement $announcement): Announcement
    {
        $announcement->recipients_count = $this->recipients($announcement)->count();
        $announcement->sent_at = now();
        $announcement->save();

        SendAnnouncementJob::dispatch($announcement->id);

        return $announcement;
    }

    /**
     * Deliver to everyone, in chunks.
     *
     * Chunked rather than one Notification::send over the whole set: a
     * broadcast to the entire userbase would otherwise hydrate every user
     * into memory at once, and the point at which that stops fitting is the
     * point at which the platform is doing well.
     */
    public function deliver(Announcement $announcement): void
    {
        $notification = AnnouncementNotification::for($announcement);

        $this->recipients($announcement)
            ->select(['id', 'name', 'email', 'phone', 'phone_verified_at'])
            ->chunkById(200, function ($users) use ($notification): void {
                Notification::send($users, $notification);
            });
    }
}
