<?php

namespace App\Modules\Auth\Services;

use App\Models\LoginEvent;
use App\Models\User;
use App\Modules\Auth\Notifications\NewDeviceLoginNotification;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Security\DeviceFingerprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Everything that must happen when a user's session is established, whatever
 * the entry path — password, OTP code, or social login: device/login-event
 * recording, new-device email alert, last-login stamp, audit trail
 * (docs/FirstMaket_PRD_Laravel.md "Authentication and Identity").
 */
class SessionAuthenticator
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    public function establish(User $user, Request $request, string $method = 'password'): void
    {
        Auth::login($user, remember: $request->boolean('remember'));

        $request->session()->regenerate();

        $this->recordLogin($user, $request, $method);
    }

    /**
     * For flows where the guard already logged the user in (Auth::attempt);
     * records the login trail without re-logging-in.
     */
    public function recordLogin(User $user, Request $request, string $method = 'password'): void
    {
        $fingerprint = DeviceFingerprint::fromRequest($request);

        $isNewDevice = ! LoginEvent::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->exists();

        // Alert only when the account has logged in before from some other
        // device — the very first login after registration is always "new"
        // and alerting on it would just be noise.
        $shouldAlert = $isNewDevice && LoginEvent::query()->where('user_id', $user->id)->exists();

        $loginEvent = LoginEvent::query()->create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'device_fingerprint' => $fingerprint,
            'is_new_device' => $isNewDevice,
        ]);

        if ($shouldAlert && $user->email !== null) {
            $user->notify(new NewDeviceLoginNotification($request->ip() ?? 'unknown', (string) $request->userAgent()));
            $loginEvent->forceFill(['notified_at' => now()])->save();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->auditLogger->log(actor: $user, subject: $user, action: 'auth.login', newValues: ['method' => $method]);
    }
}
