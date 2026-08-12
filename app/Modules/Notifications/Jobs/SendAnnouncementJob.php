<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Services\AnnouncementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans one announcement out to its recipients.
 *
 * Takes an id rather than the model: SerializesModels would re-resolve the
 * row on the worker anyway, and an id keeps the payload the same size whether
 * the broadcast reaches one person or fifty thousand.
 *
 * Not retried. A half-delivered broadcast retried from the top would send the
 * whole thing twice to everyone the first attempt reached, which is worse for
 * the recipient than a message that did not arrive.
 */
class SendAnnouncementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $announcementId) {}

    public function handle(AnnouncementService $announcements): void
    {
        $announcement = Announcement::query()->find($this->announcementId);

        if ($announcement === null) {
            return;
        }

        $announcements->deliver($announcement);
    }
}
