<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Services\AuthIdentifier;
use App\Modules\Identity\Services\OtpService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OtpPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account settings (Sprint 2 Addendum): add/verify the secondary identifier
 * (email accounts add a phone and vice-versa), set or change the password —
 * including social-only accounts setting their first one — and view/unlink
 * social logins. Unlinking is refused when it would leave no way to sign in.
 */
class AccountSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $user->load('socialAccounts');

        return Inertia::render('Account/Settings', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'emailVerified' => $user->email_verified_at !== null,
                'phone' => $user->phone,
                'phoneVerified' => $user->phone_verified_at !== null,
                'hasPassword' => $user->password !== null,
                'socialAccounts' => $user->socialAccounts->map(fn ($account) => [
                    'provider' => $account->provider,
                    'providerEmail' => $account->provider_email,
                    'linkedAt' => $account->created_at->toDayDateTimeString(),
                ]),
            ],
        ]);
    }

    /**
     * Step 1 of adding the secondary identifier: send an OTP through the
     * channel matching the new identifier (email → email code, phone → SMS).
     */
    public function sendIdentifierCode(Request $request, OtpService $otp): RedirectResponse
    {
        $request->validate(['identifier' => ['required', 'string', 'max:255']]);

        $identifier = AuthIdentifier::parse($request->string('identifier')->value());
        $user = $request->user();

        $this->assertSlotIsFree($user, $identifier);
        $this->assertNotTaken($user, $identifier);

        $otp->request($identifier->value, OtpPurpose::IdentityVerification, $user, $request->ip(), $identifier->channel);

        return back()->with('success', 'We sent a 6-digit code to '.$identifier->masked().'.');
    }

    /** Step 2: verify the code and attach the identifier as verified. */
    public function confirmIdentifier(Request $request, OtpService $otp, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);

        $identifier = AuthIdentifier::parse($request->string('identifier')->value());
        $user = $request->user();

        $this->assertSlotIsFree($user, $identifier);
        $this->assertNotTaken($user, $identifier);

        $otp->verify($identifier->value, OtpPurpose::IdentityVerification, $request->string('code')->value());

        $user->forceFill([
            $identifier->column() => $identifier->value,
            $identifier->isEmail() ? 'email_verified_at' : 'phone_verified_at' => now(),
        ])->save();

        $auditLogger->log(
            actor: $user,
            subject: $user,
            action: 'account.identifier_added',
            newValues: [$identifier->column() => $identifier->value],
        );

        return back()->with('success', ucfirst($identifier->isEmail() ? 'email' : 'phone number').' added and verified.');
    }

    /**
     * Set or change the password. Accounts that already have one must prove
     * it; social-only accounts set their first password without a current
     * one — that is the whole point (Sprint 2 Addendum).
     */
    public function updatePassword(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $user = $request->user();

        $rules = ['password' => ['required', 'confirmed', Password::defaults()]];

        if ($user->password !== null) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $request->validate($rules);

        $hadPassword = $user->password !== null;

        $user->forceFill(['password' => Hash::make($request->string('password')->value())])->save();

        $auditLogger->log(
            actor: $user,
            subject: $user,
            action: $hadPassword ? 'account.password_changed' : 'account.password_set',
        );

        return back()->with('success', $hadPassword ? 'Password updated.' : 'Password set — you can now sign in with it.');
    }

    public function unlinkSocial(Request $request, string $provider, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $user = $request->user();

        $account = $user->socialAccounts()->where('provider', $provider)->first();

        if ($account === null) {
            throw ValidationException::withMessages(['provider' => 'This account is not linked.']);
        }

        $hasAnotherWayIn = $user->password !== null
            || $user->socialAccounts()->where('provider', '!=', $provider)->exists();

        if (! $hasAnotherWayIn) {
            throw ValidationException::withMessages([
                'provider' => 'Set a password first — unlinking this would leave you with no way to sign in.',
            ]);
        }

        $account->delete();

        $auditLogger->log(
            actor: $user,
            subject: $user,
            action: 'account.social_unlinked',
            oldValues: ['provider' => $provider],
        );

        return back()->with('success', ucfirst($provider).' account unlinked.');
    }

    /** The account may only add the identifier type it does not have yet. */
    private function assertSlotIsFree(User $user, AuthIdentifier $identifier): void
    {
        $current = $identifier->isEmail() ? $user->email : $user->phone;

        if ($current !== null) {
            throw ValidationException::withMessages([
                'identifier' => 'This account already has '.($identifier->isEmail() ? 'an email address' : 'a phone number').'.',
            ]);
        }
    }

    /** No two accounts may share an identifier, whatever path added it. */
    private function assertNotTaken(User $user, AuthIdentifier $identifier): void
    {
        $taken = User::query()
            ->where($identifier->column(), $identifier->value)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'identifier' => 'This '.($identifier->isEmail() ? 'email' : 'phone number').' is already in use on another account.',
            ]);
        }
    }
}
