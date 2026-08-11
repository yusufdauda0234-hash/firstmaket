<?php

namespace App\Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "set your password" email a staff member gets — when their account is
 * first created, and whenever they ask for a reset.
 *
 * A dedicated notification rather than Laravel's built-in ResetPassword,
 * because the link has to land on the admin subdomain. The built-in one
 * resolves route('password.reset'), which does not exist in this app.
 *
 * The wording changes with {@see $isNewAccount}: "your account has been
 * created, choose a password" and "somebody asked to reset your password"
 * are different messages, and a new joiner told the second one reasonably
 * wonders who has been in their account.
 */
class StaffPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $email,
        private readonly int $expiresInMinutes,
        private readonly bool $isNewAccount = false,
        private readonly ?string $roleName = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->isNewAccount
                ? 'Your FirstMaket staff account — set your password'
                : 'Reset your FirstMaket staff password')
            ->greeting('Hello '.($notifiable->name ?? '').',');

        if ($this->isNewAccount) {
            $message
                ->line($this->roleName !== null
                    ? "An administrator has created a FirstMaket staff account for you as {$this->roleName}."
                    : 'An administrator has created a FirstMaket staff account for you.')
                ->action('Choose your password', $this->resetUrl())
                ->line("This link expires in {$this->expiresInMinutes} minutes and can only be used once.")
                ->line('If it expires before you get to it, ask whoever set the account up to send another.');
        } else {
            $message
                ->line('We received a request to reset the password on your FirstMaket staff account.')
                ->action('Set a new password', $this->resetUrl())
                ->line("This link expires in {$this->expiresInMinutes} minutes and can only be used once.")
                ->line('If you did not ask for this, you can ignore this email — your password stays as it is.');
        }

        // Staff accounts reach money, customer records and settings, so the
        // reminder is worth the line even though it is obvious to some.
        return $message->line('Never share this link. FirstMaket staff will never ask you for it.');
    }

    /**
     * Absolute URL on the admin subdomain, built by hand rather than through
     * route(): these emails are queued and rendered outside a request, where
     * a relative route would resolve against the wrong host.
     */
    private function resetUrl(): string
    {
        $host = rtrim((string) config('app.admin_domain'), '/');
        $scheme = str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http';
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return sprintf(
            '%s://%s%s/reset-password/%s?email=%s',
            $scheme,
            $host,
            $port ? ':'.$port : '',
            $this->token,
            urlencode($this->email),
        );
    }
}
