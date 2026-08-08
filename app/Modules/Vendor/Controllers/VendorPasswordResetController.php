<?php

namespace App\Modules\Vendor\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Contracts\AuditLoggerContract;
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
}
