<?php

namespace App\Modules\Referrals\Notifications;

use App\Modules\Referrals\Models\Referral;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class ReferralRewardNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Referral $referral) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'title' => 'Your referral reward is earned',
            'body' => 'Your friend completed their first Pay Small Small plan. Your referral reward is now qualified.',
            'url' => route('referrals.index'),
        ]);
    }
}
