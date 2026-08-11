<?php

namespace App\Modules\Vendor\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Vendor\Notifications\VendorPasswordResetNotification;
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
 * Where a vendor lands from the reset email: a form to choose a new password.
 *
 * Lives on the Vendor Center subdomain so the whole journey stays in the
 * portal the vendor actually signs in to. Laravel's password broker owns the
 * token — its hashing, its one-time use, and its 60-minute expiry — so nothing
 * about token security is reimplemented here.
 */
class VendorPasswordResetController extends Controller
{
    /** The "I forgot my password" form on the Vendor Center sign-in page. */
    public function request(): Response
    {
        return Inertia::render('Auth/VendorForgotPassword');
    }

    /**
     * Email a reset link — or appear to.
     *
     * Always reports success. Saying "no such account" would turn this form
     * into a way to discover which addresses sell on FirstMaket, and a list
     * of verified sellers is worth having to anyone running a scam against
     * them.
     *
     * Previously a vendor who forgot their password had to ask staff to
     * reset it for them; this is the same email, asked for by the vendor.
     */
    public function send(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $vendor = User::query()
            ->where('email', $validated['email'])
            ->where('user_type', UserType::Vendor)
            ->first();

        // A suspended vendor must not be able to let themselves back in.
        if ($vendor !== null && $vendor->status === UserStatus::Active) {
            try {
                $vendor->notify(new VendorPasswordResetNotification(
                    Password::broker()->createToken($vendor),
                    (string) $vendor->email,
                    $this->expiryMinutes(),
                ));

                $auditLogger->log(
                    actor: $vendor,
                    subject: $vendor,
                    action: 'vendor.password_reset_requested',
                );
            } catch (\Throwable $e) {
                // Reported, not shown: the response is identical either way,
                // so a failing mailer cannot be used to probe for accounts.
                report($e);
            }
        }

        return back()->with(
            'success',
            'If that address belongs to a FirstMaket vendor account, a link to set a new password is on its way. It expires in '.$this->expiryMinutes().' minutes.',
        );
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/VendorResetPassword', [
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

        // Scoped to vendors: this form is on the Vendor Center, and a token
        // issued here must not be usable to take over a staff or customer
        // account if an address is ever shared between them.
        $vendor = User::query()
            ->where('email', $request->string('email')->value())
            ->where('user_type', UserType::Vendor)
            ->first();

        if ($vendor === null) {
            return back()->withErrors([
                'email' => 'That link is not for a vendor account.',
            ]);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors([
                // Covers expired, already-used and tampered tokens alike —
                // distinguishing them would tell an attacker which it was.
                'email' => 'That reset link is no longer valid. Ask an administrator to send a new one.',
            ]);
        }

        $auditLogger->log(actor: $vendor, subject: $vendor, action: 'vendor.password_reset_completed');

        return redirect()
            ->route('vendor.login')
            ->with('success', 'Your password is set. Sign in to the Vendor Center below.');
    }

    private function expiryMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }
}
