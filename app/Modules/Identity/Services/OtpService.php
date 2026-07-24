<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\OtpCode;
use App\Modules\Identity\Notifications\OtpCodeNotification;
use App\Shared\Contracts\SmsSenderContract;
use App\Shared\Enums\OtpChannel;
use App\Shared\Enums\OtpPurpose;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * OTP lifecycle: hashed-at-rest codes, short expiry, per-destination request
 * throttling, and a hard attempt cap per code. Codes travel by SMS or email —
 * whichever channel matches the identifier the user signed up with
 * (docs/FirstMaket_Implementation_Plan.md Sprint 2 + Sprint 2 Addendum,
 * docs/FirstMaket_Security_Compliance.md).
 */
class OtpService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const MAX_REQUESTS_PER_WINDOW = 3;

    public const REQUEST_WINDOW_MINUTES = 15;

    public function __construct(private readonly SmsSenderContract $sms) {}

    /**
     * Generate and send a code, invalidating earlier unverified codes for the
     * same destination/purpose so only the newest one is redeemable.
     *
     * @throws ValidationException when the destination has hit the request throttle
     */
    public function request(
        string $destination,
        OtpPurpose $purpose,
        ?User $user = null,
        ?string $requestIp = null,
        OtpChannel $channel = OtpChannel::Sms,
    ): OtpCode {
        $recentRequests = OtpCode::query()
            ->where('destination', $destination)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subMinutes(self::REQUEST_WINDOW_MINUTES))
            ->count();

        if ($recentRequests >= self::MAX_REQUESTS_PER_WINDOW) {
            throw ValidationException::withMessages([
                'identifier' => 'Too many code requests. Please wait a few minutes and try again.',
            ]);
        }

        OtpCode::query()
            ->where('destination', $destination)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->update(['expires_at' => now()]);

        $code = (string) random_int(100000, 999999);

        $otp = OtpCode::query()->create([
            'user_id' => $user?->id,
            'channel' => $channel,
            'destination' => $destination,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'request_ip' => $requestIp,
        ]);

        try {
            match ($channel) {
                OtpChannel::Sms => $this->sms->send(
                    $destination,
                    "Your FirstMarketverification code is {$code}. It expires in ".self::CODE_TTL_MINUTES.' minutes. Never share this code.'
                ),
                OtpChannel::Email => Notification::route('mail', $destination)
                    ->notify(new OtpCodeNotification($code, self::CODE_TTL_MINUTES)),
            };
        } catch (\Throwable $e) {
            // Delivery failed at the gateway (bad credentials, no routing,
            // provider outage) rather than our own validation — surface it
            // the same way as any other form error instead of an uncaught
            // 500 (which Inertia turns into a silent full-page reload), and
            // free up the destination so the throttle above doesn't also
            // block a retry with a code that was never delivered.
            $otp->delete();

            throw ValidationException::withMessages([
                'identifier' => 'We could not send your verification code right now. Please try again shortly.',
            ]);
        }

        return $otp;
    }

    /**
     * @throws ValidationException when the code is missing, expired, burned
     *                             by too many attempts, or wrong
     */
    public function verify(string $destination, OtpPurpose $purpose, string $code): OtpCode
    {
        $otp = OtpCode::query()
            ->where('destination', $destination)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if ($otp === null || $otp->isExpired()) {
            throw ValidationException::withMessages([
                'code' => 'This code has expired. Please request a new one.',
            ]);
        }

        if ($otp->attempt_count >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => 'Too many incorrect attempts. Please request a new code.',
            ]);
        }

        $otp->increment('attempt_count');

        if (! Hash::check($code, $otp->code_hash)) {
            throw ValidationException::withMessages([
                'code' => 'The code you entered is incorrect.',
            ]);
        }

        $otp->forceFill(['verified_at' => now()])->save();

        return $otp;
    }
}
