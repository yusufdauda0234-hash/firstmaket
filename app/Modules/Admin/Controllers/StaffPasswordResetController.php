<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Notifications\StaffPasswordResetNotification;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff password recovery: asking for a link, and landing on it.
 *
 * Staff previously had neither. A new joiner was emailed a six-digit code
 * and had to find somewhere to type it, and anybody who forgot their
 * password had to ask another administrator to intervene. Both are now a
 * link, matching the flow vendors already had.
 *
 * Laravel's password broker owns the token — hashing, one-time use, and the
 * 60-minute expiry — so nothing about token security is reimplemented here.
 *
 * Scoping matters on this portal. A token issued from the staff form must
 * never open a customer or vendor account, and vice versa, in case an
 * address is ever shared between them. Every lookup below therefore filters
 * on `user_type` as well as email.
 */
class StaffPasswordResetController extends Controller
{
    /** The "I forgot my password" form on the admin sign-in page. */
    public function request(): Response
    {
        return Inertia::render('Admin/Auth/ForgotPassword');
    }

    /**
     * Email a reset link — or appear to.
     *
     * Always reports success. Saying "no such account" here would turn the
     * form into a way to discover which addresses belong to FirstMaket
     * staff, which is exactly the list somebody planning a phishing run
     * would want.
     */
    public function send(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $staff = User::query()
            ->where('email', $validated['email'])
            ->where('user_type', UserType::Staff)
            ->first();

        // A suspended or banned account must not be able to let itself back
        // in by resetting its own password.
        if ($staff !== null && $staff->status === UserStatus::Active) {
            try {
                $staff->notify(new StaffPasswordResetNotification(
                    Password::broker()->createToken($staff),
                    (string) $staff->email,
                    $this->expiryMinutes(),
                ));

                $auditLogger->log(
                    actor: $staff,
                    subject: $staff,
                    action: 'admin.staff_password_reset_requested',
                );
            } catch (\Throwable $e) {
                // Reported, not shown: the response is identical either way,
                // so a failing mailer cannot be used to probe for accounts.
                report($e);
            }
        }

        return back()->with(
            'success',
            'If that address belongs to a FirstMaket staff account, a link to set a new password is on its way. It expires in '.$this->expiryMinutes().' minutes.',
        );
    }

    /** Where the emailed link lands. */
    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Admin/Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $staff = User::query()
            ->where('email', $request->string('email')->value())
            ->where('user_type', UserType::Staff)
            ->first();

        if ($staff === null) {
            return back()->withErrors(['email' => 'That link is not for a staff account.']);
        }

        if ($staff->status !== UserStatus::Active) {
            return back()->withErrors([
                'email' => 'This account is not active. Contact an administrator.',
            ]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // Invalidates "remember me" cookies everywhere, so a
                    // reset genuinely ends any session someone else had.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors([
                // Covers expired, already-used and tampered tokens alike —
                // distinguishing them would tell an attacker which it was.
                'email' => 'That link is no longer valid. Ask for a new one from the sign-in page.',
            ]);
        }

        $auditLogger->log(
            actor: $staff,
            subject: $staff,
            action: 'admin.staff_password_reset_completed',
        );

        return redirect()
            ->route('admin.login')
            ->with('success', 'Your password is set. Sign in below.');
    }

    private function expiryMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }
}
