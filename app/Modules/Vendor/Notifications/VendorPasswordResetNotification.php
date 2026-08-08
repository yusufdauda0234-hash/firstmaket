<?php

namespace App\Modules\Vendor\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "set a new password" email a vendor gets when staff reset them.
 *
 * A dedicated notification rather than Laravel's built-in ResetPassword because
 * the link has to land on the Vendor Center subdomain, not the customer site —
 * the built-in one resolves route('password.reset'), which does not exist here
 * and threw when it was rendered.
 */
class VendorPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $email,
        private readonly int $expiresInMinutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set your FirstMaket vendor password')
            ->greeting('Hello')
            ->line('A FirstMaket administrator has asked us to reset the password on your vendor account.')
            ->action('Set a new password', $this->resetUrl())
            ->line("This link expires in {$this->expiresInMinutes} minutes and can be used once.")
            ->line('If you were not expecting this, you can ignore this email — your password stays as it is.');
    }

    /**
     * Absolute URL on the Vendor Center, built by hand rather than through
     * route() because these emails are rendered from the admin subdomain, where
     * a relative route would generate the wrong host.
     */
    private function resetUrl(): string
    {
        $host = rtrim((string) config('app.vendor_domain'), '/');
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
