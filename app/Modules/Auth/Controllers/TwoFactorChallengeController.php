<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Services\SessionAuthenticator;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Security\TwoFactorCodes;
use App\Shared\Security\TwoFactorDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second step of a staff sign-in: password accepted, now prove the device.
 *
 * The account is deliberately NOT authenticated while this page is open. Only
 * the user's id sits in the session, so a half-finished sign-in grants nothing
 * — before this existed, an enrolled authenticator app was a one-time setup
 * ritual and a stolen password was enough on its own.
 */
class TwoFactorChallengeController extends Controller
{
    /** Session keys holding the half-finished sign-in. */
    public const PENDING_USER = 'two_factor.pending_user_id';

    public const PENDING_REMEMBER = 'two_factor.pending_remember';

    private const MAX_ATTEMPTS = 5;

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('admin.login');
        }

        return Inertia::render('Admin/Auth/TwoFactorChallenge', [
            'email' => $user->email,
            'recoveryCodesLeft' => app(TwoFactorCodes::class)->remainingRecoveryCodes($user),
        ]);
    }

    public function store(
        Request $request,
        TwoFactorCodes $codes,
        TwoFactorDevices $devices,
        SessionAuthenticator $authenticator,
        AuditLoggerContract $auditLogger,
    ): RedirectResponse {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('admin.login')
                ->withErrors(['code' => 'That sign-in expired. Please enter your password again.']);
        }

        $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'remember_device' => ['boolean'],
        ]);

        // A 6-digit code is guessable in ~1M tries; without a limit on the
        // challenge the second factor would add very little.
        $this->ensureNotRateLimited($user);

        $submitted = $request->string('code')->value();

        $viaTotp = $codes->verifyTotp($user, $submitted);
        $viaRecovery = ! $viaTotp && $codes->verifyRecoveryCode($user, $submitted);

        if (! $viaTotp && ! $viaRecovery) {
            RateLimiter::hit($this->throttleKey($user), 900);

            $auditLogger->log(actor: $user, subject: $user, action: 'auth.two_factor_failed');

            throw ValidationException::withMessages([
                'code' => 'That code is not valid. Check your authenticator app and try again.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($user));

        $request->session()->forget([self::PENDING_USER, self::PENDING_REMEMBER]);

        // establish() logs in, rotates the session, and records the login trail.
        $authenticator->establish($user, $request, method: $viaRecovery ? 'password+recovery_code' : 'password+totp');

        if ($viaRecovery) {
            $auditLogger->log(
                actor: $user,
                subject: $user,
                action: 'auth.two_factor_recovery_code_used',
                newValues: ['codes_remaining' => $codes->remainingRecoveryCodes($user)],
            );
        }

        $response = redirect()->intended(route('admin.dashboard'));

        // A recovery code means the authenticator is unavailable, so trusting
        // the device would paper over an account that needs attention.
        if ($request->boolean('remember_device') && ! $viaRecovery) {
            $response->withCookie($devices->remember($user, $request));
        }

        return $response;
    }

    /** Abandon the half-finished sign-in and go back to the password form. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget([self::PENDING_USER, self::PENDING_REMEMBER]);

        return redirect()->route('admin.login');
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_USER);

        return $id === null ? null : User::query()->find($id);
    }

    private function ensureNotRateLimited(User $user): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($user), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($user));

        throw ValidationException::withMessages([
            'code' => "Too many attempts. Try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(User $user): string
    {
        return 'two-factor:'.$user->id;
    }
}
