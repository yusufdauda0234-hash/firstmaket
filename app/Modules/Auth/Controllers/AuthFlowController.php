<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Events\UserIdentifierVerified;
use App\Modules\Auth\Services\AuthIdentifier;
use App\Modules\Auth\Services\PostAuthRedirect;
use App\Modules\Auth\Services\SessionAuthenticator;
use App\Modules\Identity\Services\OtpService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OtpPurpose;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The step endpoints behind the combined sign-in/register modal
 * (AliExpress-style, Sprint 2 Addendum): identify the email-or-phone input,
 * send/verify OTP codes through the matching channel, passwordless code
 * login, and code-verified password reset.
 *
 * identify/sendCode/verifyCode return JSON for the modal's step machine;
 * loginWithCode/resetPassword complete a session and redirect (Inertia).
 */
class AuthFlowController extends Controller
{
    public const VERIFIED_SESSION_KEY = 'auth_flow.verified_registration';

    public const VERIFIED_TTL_MINUTES = 15;

    public function __construct(
        private readonly OtpService $otp,
        private readonly SessionAuthenticator $authenticator,
    ) {}

    /** Step 1: does this email/phone already have an account? */
    public function identify(Request $request): JsonResponse
    {
        $identifier = $this->parseIdentifier($request);

        $user = $this->findCustomerFacingUser($identifier);

        return response()->json([
            'exists' => $user !== null,
            'channel' => $identifier->channel->value,
            'identifier' => $identifier->value,
            'masked' => $identifier->masked(),
        ]);
    }

    /** Send an OTP for registration, login, or password reset. */
    public function sendCode(Request $request): JsonResponse
    {
        $request->validate(['purpose' => ['required', 'in:registration,login,password_reset']]);

        $identifier = $this->parseIdentifier($request);
        $purpose = OtpPurpose::from($request->string('purpose')->value());
        $user = $this->findCustomerFacingUser($identifier);

        if ($purpose === OtpPurpose::Registration && $user !== null) {
            throw ValidationException::withMessages([
                'identifier' => 'An account with this '.($identifier->isEmail() ? 'email' : 'phone number').' already exists. Sign in instead.',
            ]);
        }

        if ($purpose !== OtpPurpose::Registration && $user === null) {
            throw ValidationException::withMessages([
                'identifier' => 'We could not find an account for this '.($identifier->isEmail() ? 'email' : 'phone number').'.',
            ]);
        }

        $this->otp->request($identifier->value, $purpose, $user, $request->ip(), $identifier->channel);

        return response()->json(['sent' => true, 'masked' => $identifier->masked()]);
    }

    /**
     * Verify a registration code. On success the identifier is marked
     * verified in the session so the follow-up POST /register (name +
     * password) can trust it without re-sending the code.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $identifier = $this->parseIdentifier($request);

        $this->otp->verify($identifier->value, OtpPurpose::Registration, $request->string('code')->value());

        $request->session()->put(self::VERIFIED_SESSION_KEY, [
            'identifier' => $identifier->value,
            'channel' => $identifier->channel->value,
            'verified_until' => now()->addMinutes(self::VERIFIED_TTL_MINUTES)->getTimestamp(),
        ]);

        return response()->json(['verified' => true]);
    }

    /** Passwordless login: email/phone + fresh OTP code. */
    public function loginWithCode(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $identifier = $this->parseIdentifier($request);
        $user = $this->findCustomerFacingUser($identifier);

        if ($user === null) {
            throw ValidationException::withMessages(['identifier' => 'We could not find an account for this identifier.']);
        }

        $this->assertNotBlocked($user, $identifier);

        $this->otp->verify($identifier->value, OtpPurpose::Login, $request->string('code')->value());

        $this->markIdentifierVerified($user, $identifier);

        $this->authenticator->establish($user, $request, method: 'otp');

        return redirect()->intended(PostAuthRedirect::customer($request));
    }

    /** Code-verified password reset: identifier + code + new password, then sign in. */
    public function resetPassword(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $identifier = $this->parseIdentifier($request);
        $user = $this->findCustomerFacingUser($identifier);

        if ($user === null) {
            throw ValidationException::withMessages(['identifier' => 'We could not find an account for this identifier.']);
        }

        $this->assertNotBlocked($user, $identifier);

        $this->otp->verify($identifier->value, OtpPurpose::PasswordReset, $request->string('code')->value());

        $user->forceFill(['password' => Hash::make($request->string('password')->value())])->save();

        $this->markIdentifierVerified($user, $identifier);

        $auditLogger->log(actor: $user, subject: $user, action: 'auth.password_reset');

        $this->authenticator->establish($user, $request, method: 'password_reset');

        return redirect()->route('dashboard')->with('success', 'Your password has been reset.');
    }

    private function parseIdentifier(Request $request): AuthIdentifier
    {
        $request->validate(['identifier' => ['required', 'string', 'max:255']]);

        return AuthIdentifier::parse($request->string('identifier')->value());
    }

    /**
     * Staff accounts are invisible to the public auth flow — they sign in
     * only on the admin subdomain (Sprint 1 domain isolation).
     */
    private function findCustomerFacingUser(AuthIdentifier $identifier): ?User
    {
        return User::query()
            ->where($identifier->column(), $identifier->value)
            ->where('user_type', '!=', UserType::Staff)
            ->first();
    }

    private function assertNotBlocked(User $user, AuthIdentifier $identifier): void
    {
        if (in_array($user->status, [UserStatus::Suspended, UserStatus::Banned], true)) {
            throw ValidationException::withMessages([
                'identifier' => 'Your account has been '.$user->status->value.'. Contact support for assistance.',
            ]);
        }
    }

    /** An OTP that reached the account proves the channel it traveled through. */
    private function markIdentifierVerified(User $user, AuthIdentifier $identifier): void
    {
        $column = $identifier->isEmail() ? 'email_verified_at' : 'phone_verified_at';

        if ($user->{$column} !== null) {
            return;
        }

        $user->forceFill([$column => now()])->save();

        // A referred account reaching verified status is a stronger signal
        // than a bare signup, and partners are measured on it. Recorded once,
        // the first time either channel is proved.
        event(new UserIdentifierVerified($user->id));
    }
}
