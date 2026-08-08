<?php

namespace App\Modules\Savings\Notifications;

use App\Modules\Notifications\Services\PreferenceAwareNotification;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\NotificationCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The first instalment never arrived, so the plan was revoked and its price
 * lock released.
 *
 * Says plainly where any money went: to credit against another plan. There
 * is no cash refund anywhere in FirstMaket, and a message that implied one
 * would generate support work rather than save it.
 */
class PlanRevokedNotification extends PreferenceAwareNotification implements ShouldQueue
{
    public function __construct(
        private readonly SavingsGoal $goal,
        private readonly int $creditKobo,
    ) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Savings;
    }

    private function naira(int $kobo): string
    {
        return '₦'.number_format($kobo / 100);
    }

    private function creditLine(): string
    {
        return $this->creditKobo > 0
            ? 'The '.$this->naira($this->creditKobo).' you had already paid is now credit on your account, '
                .'and goes towards your next plan automatically.'
            : 'Nothing had been paid, so there is nothing outstanding.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Pay Small Small plan was cancelled')
            ->line('Your plan for '.$this->naira($this->goal->target_kobo)
                .' was cancelled because the first payment was not made in time.')
            ->line($this->creditLine())
            ->line('You can start a new plan at any time — the price will be whatever it is on the day.');
    }

    public function toSms(object $notifiable): string
    {
        return 'FirstMaket: your plan was cancelled — the first payment was not made in time. '
            .($this->creditKobo > 0
                ? $this->naira($this->creditKobo).' is saved as credit for your next plan.'
                : 'Nothing was charged.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Plan cancelled — first payment missed',
            'body' => 'Your plan for '.$this->naira($this->goal->target_kobo).' was cancelled. '
                .$this->creditLine(),
            'url' => route('savings.index', absolute: false),
        ];
    }
}
