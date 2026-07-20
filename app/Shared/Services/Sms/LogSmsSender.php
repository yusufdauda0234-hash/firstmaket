<?php

namespace App\Shared\Services\Sms;

use App\Shared\Contracts\SmsSenderContract;
use Illuminate\Support\Facades\Log;

/**
 * Local/testing driver: writes the SMS to the log instead of a gateway, the
 * same way MAIL_MAILER=log works for email.
 */
class LogSmsSender implements SmsSenderContract
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS (log driver)', ['phone' => $phone, 'message' => $message]);
    }
}
