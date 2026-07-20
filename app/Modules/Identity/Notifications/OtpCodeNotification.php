<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email-channel OTP. The plaintext code exists only in this message — the
 * database stores a hash (docs/firstmarket_Security_Compliance.md).
 */
class OtpCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your FirstMarket code: {$this->code}")
            ->view('emails.otp', [
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
            ]);
    }
}
