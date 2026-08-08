<?php

namespace App\Shared\Security;

use App\Models\TwoFactorDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * "Remember this device for 30 days" — the thing that makes a mandatory second
 * factor bearable on a machine someone uses every day.
 *
 * The cookie holds a random token; only its SHA-256 lives in the database, so
 * neither a stolen database nor a stolen backup yields a working bypass. Trust
 * is per-device and revocable, and every device is dropped the moment 2FA is
 * re-enrolled.
 */
final class TwoFactorDevices
{
    public const COOKIE = 'fm_2fa_device';

    private const TRUST_DAYS = 30;

    /** True when this browser already passed the challenge and may skip it. */
    public function isTrusted(User $user, Request $request): bool
    {
        $token = (string) $request->cookie(self::COOKIE);

        if ($token === '') {
            return false;
        }

        $device = TwoFactorDevice::query()
            ->live()
            ->where('user_id', $user->id)
            ->where('token_hash', $this->hash($token))
            ->first();

        if ($device === null) {
            return false;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /**
     * Trust this browser and return the cookie to attach to the response.
     *
     * Deliberately not `Cookie::queue` — the caller owns the response, and a
     * queued cookie is easy to lose across a redirect chain.
     */
    public function remember(User $user, Request $request): SymfonyCookie
    {
        $token = Str::random(64);

        TwoFactorDevice::query()->create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($token),
            'label' => $this->label($request),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::TRUST_DAYS),
        ]);

        $this->pruneExpired($user);

        return Cookie::make(self::COOKIE, $token, self::TRUST_DAYS * 24 * 60);
    }

    /** Drop every trusted device — used when 2FA is re-enrolled. */
    public function forgetAll(User $user): void
    {
        TwoFactorDevice::query()->where('user_id', $user->id)->delete();
    }

    private function pruneExpired(User $user): void
    {
        TwoFactorDevice::query()
            ->where('user_id', $user->id)
            ->where('expires_at', '<=', now())
            ->delete();
    }

    /** Something recognisable in a device list, not a fingerprint. */
    private function label(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'browser',
        };

        return "{$browser} on {$platform}";
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
