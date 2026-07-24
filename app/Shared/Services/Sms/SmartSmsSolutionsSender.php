<?php

namespace App\Shared\Services\Sms;

use App\Shared\Contracts\SmsSenderContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SmartSMSSolutions gateway driver (https://smartsmssolutions.com). Selected
 * when SMS_PROVIDER_DRIVER=smartsmssolutions; account/token setup is
 * described in the README's provider section. The API's own docs report
 * failures three different ways across their endpoint family
 * (`status: "error"` on the email endpoints, `success: false` on VoiceOTP,
 * `error: true` with a numeric `code` on the plain SMS endpoint used here —
 * e.g. code 1002 "No valid routing supplied" when the account has no active
 * route/sender ID), so all three are checked alongside the HTTP status.
 */
class SmartSmsSolutionsSender implements SmsSenderContract
{
    /**
     * @throws ConnectionException
     */
    public function send(string $phone, string $message): void
    {
        $response = Http::asMultipart()->post('https://app.smartsmssolutions.com/io/api/client/v1/sms/', [
            ['name' => 'token', 'contents' => config('services.sms.key')],
            ['name' => 'sender', 'contents' => config('services.sms.sender_id')],
            ['name' => 'to', 'contents' => $phone],
            ['name' => 'message', 'contents' => $message],
            ['name' => 'type', 'contents' => 'plain'],
        ]);

        $failed = $response->failed()
            || $response->json('status') === 'error'
            || $response->json('success') === false
            || $response->json('error') === true;

        if ($failed) {
            throw new RuntimeException('SmartSMSSolutions SMS send failed: '.$response->body());
        }
    }
}
