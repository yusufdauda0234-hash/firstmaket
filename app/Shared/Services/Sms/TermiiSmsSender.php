<?php

namespace App\Shared\Services\Sms;

use App\Shared\Contracts\SmsSenderContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Termii gateway driver (https://developers.termii.com). Selected when
 * SMS_PROVIDER_DRIVER=termii; sandbox setup is described in the README's
 * provider section.
 */
class TermiiSmsSender implements SmsSenderContract
{
    /**
     * @throws ConnectionException
     */
    public function send(string $phone, string $message): void
    {
        $response = Http::asJson()->post('https://api.ng.termii.com/api/sms/send', [
            'api_key' => config('services.sms.key'),
            'from' => config('services.sms.sender_id'),
            'to' => $phone,
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'generic',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Termii SMS send failed: '.$response->body());
        }
    }
}
