<?php

namespace App\Modules\Savings\Notifications;

use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The plan has fallen far enough behind to be at risk, and nobody should lose
 * one without being told first.
 *
 * Written as a nudge rather than a threat: the way out is one payment, and
 * saying so plainly is more likely to recover the plan than warning about
 * consequences. It also names the alternative — switching to something
 * cheaper — because a customer who has stopped paying often cannot afford the
 * thing they picked, not saving in general.
 */
class PlanDormantNotification extends PreferenceAwareNotification implements ShouldQueue
{
    public function __construct(private readonly SavingsGoal $goal) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Savings;
    }

    private function naira(int $kobo): string
    {
        return '₦'.number_format($kobo / 100);
    }

    private function nextPayment(): string
    {
        return $this->naira($this->goal->nextPaymentKobo());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Pay Small Small plan needs a payment')
            ->line('Your plan for '.$this->naira($this->goal->target_kobo).' has missed a few payments.')
            ->line('One payment of '.$this->nextPayment().' brings it back on track and keeps your locked price.')
            ->line('If the item is no longer what you want, you can switch the plan to something else instead —'
                .' everything you have paid moves with it.')
            ->line('If nothing is paid, the plan will be closed and your payments kept as credit for a future one.');
    }

    public function toSms(object $notifiable): string
    {
        return 'FirstMaket: your plan has missed a few payments. Pay '.$this->nextPayment()
            .' to keep it, or switch it to another item. Nothing is lost either way.';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Your plan needs a payment',
            'body' => 'One payment of '.$this->nextPayment().' keeps this plan and its locked price. '
                .'You can also switch it to another item — your payments move with it.',
            'url' => route('savings.goals.show', $this->goal->uuid, absolute: false),
        ];
    }
}
