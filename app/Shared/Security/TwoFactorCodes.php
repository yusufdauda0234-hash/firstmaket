<?php

namespace App\Shared\Security;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Verifying the second factor, and the recovery codes that stand in for it.
 *
 * Lives in Shared because two places need it and neither may depend on the
 * other: enrollment sits in the Admin module, the login challenge in Auth.
 */
final class TwoFactorCodes
{
    /**
     * Enough codes that losing a phone is survivable, few enough that people
     * actually store them.
     */
    private const RECOVERY_CODE_COUNT = 8;

    /** TOTP step. google2fa counts time in these, not in seconds. */
    private const WINDOW_SECONDS = 30;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Fresh recovery codes, hashed at rest and returned in plain text once.
     *
     * Hashed rather than encrypted on purpose: nothing ever needs to read a
     * recovery code back, only compare against one, so a database leak must
     * not hand over usable codes. This is also why they can only be shown at
     * the moment they are generated.
     *
     * @return list<string> the plain-text codes, to display once and never again
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // Grouped halves are far easier to read off paper than 10 run-on
            // characters, and the dash is stripped before comparison.
            $code = Str::lower(Str::random(5).'-'.Str::random(5));
            $plain[] = $code;
            $hashed[] = Hash::make($this->normalise($code));
        }

        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($hashed)),
        ])->save();

        return $plain;
    }

    /** How many unused recovery codes remain. */
    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->storedRecoveryCodes($user));
    }

    /**
     * Check a TOTP code from the authenticator app.
     *
     * Rejects a code that has already been accepted: Google Authenticator
     * codes stay valid for a window of seconds, so without this a code read
     * over someone's shoulder — or captured in a proxy — could be replayed
     * within that window.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        $timestamp = $this->google2fa->verifyKeyNewer($secret, $code, $this->lastUsedWindow($user));

        if ($timestamp === false) {
            return false;
        }

        // verifyKeyNewer speaks in WINDOWS (unix seconds / 30), not unix
        // seconds, so the value has to be scaled before it can be stored as a
        // datetime — and scaled back on the way in. Storing it raw made every
        // future code look older than the watermark, which locked the account
        // out of TOTP entirely after one successful sign-in.
        $user->forceFill([
            'two_factor_last_used_at' => is_int($timestamp)
                ? Carbon::createFromTimestamp($timestamp * self::WINDOW_SECONDS)
                : now(),
        ])->save();

        return true;
    }

    /**
     * The last accepted code's window, in the units google2fa expects.
     */
    private function lastUsedWindow(User $user): ?int
    {
        if ($user->two_factor_last_used_at === null) {
            return null;
        }

        return intdiv((int) $user->two_factor_last_used_at->timestamp, self::WINDOW_SECONDS);
    }

    /**
     * Check a recovery code and burn it.
     *
     * Single use, so a code copied from a shared document stops working after
     * the first person uses it.
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $stored = $this->storedRecoveryCodes($user);
        $candidate = $this->normalise($code);

        foreach ($stored as $index => $hash) {
            if (! Hash::check($candidate, $hash)) {
                continue;
            }

            unset($stored[$index]);

            $user->forceFill([
                'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($stored))),
            ])->save();

            return true;
        }

        return false;
    }

    /** @return list<string> hashes of the unused codes */
    private function storedRecoveryCodes(User $user): array
    {
        if ($user->two_factor_recovery_codes === null) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true);
        } catch (\Throwable) {
            // Re-keyed app or corrupt payload — treat as no codes rather than
            // breaking the sign-in page.
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** Case and dashes are presentation, not secret. */
    private function normalise(string $code): string
    {
        return Str::lower(str_replace([' ', '-'], '', trim($code)));
    }
}
