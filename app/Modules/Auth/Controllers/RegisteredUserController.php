<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Requests\RegisterUserRequest;
use App\Modules\Auth\Services\PostAuthRedirect;
use App\Modules\Auth\Services\SessionAuthenticator;
use App\Modules\Customer\Models\CustomerProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OtpChannel;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-registration, OTP-first (Sprint 2 Addendum): the user enters
 * an email or phone number, proves it with a code through the matching
 * channel, then completes name + password here. The proven identifier is
 * created pre-verified; the other identifier can be added later. Phone
 * verification is optional/secondary — it does not gate savings funding or
 * Product Target Plans. There is no BVN/NIN identity verification feature
 * (docs/FirstMaket_Implementation_Plan.md Sprint 2 + Addendum).
 */
class RegisteredUserController extends Controller
{
    public function create(): RedirectResponse
    {
        // No standalone register page — the storefront modal handles the
        // combined sign-in/register flow (?auth=register opens it).
        return redirect()->route('home', ['auth' => 'register']);
    }

    public function store(RegisterUserRequest $request, AuditLoggerContract $auditLogger, SessionAuthenticator $authenticator): RedirectResponse
    {
        $verified = $request->session()->get(AuthFlowController::VERIFIED_SESSION_KEY);

        if (
            ! is_array($verified)
            || ($verified['verified_until'] ?? 0) < now()->getTimestamp()
        ) {
            throw ValidationException::withMessages([
                'identifier' => 'Please verify your email or phone number with a code first.',
            ]);
        }

        $channel = OtpChannel::from($verified['channel']);
        $identifier = $verified['identifier'];
        $column = $channel === OtpChannel::Email ? 'email' : 'phone';

        if (User::query()->where($column, $identifier)->exists()) {
            throw ValidationException::withMessages([
                'identifier' => 'An account with this '.($column === 'email' ? 'email' : 'phone number').' already exists. Sign in instead.',
            ]);
        }

        $user = DB::transaction(function () use ($request, $channel, $identifier, $column) {
            $user = User::query()->create([
                'name' => $request->string('name'),
                $column => $identifier,
                'password' => Hash::make($request->string('password')->value()),
                'user_type' => UserType::Customer,
                'status' => UserStatus::Active,
            ]);

            // The OTP already proved this channel; verified_at is guarded, so
            // stamp it explicitly rather than via mass assignment.
            $user->forceFill([
                $channel === OtpChannel::Email ? 'email_verified_at' : 'phone_verified_at' => now(),
            ])->save();

            $user->assignRole('Customer');

            CustomerProfile::query()->create(['user_id' => $user->id]);

            return $user;
        });

        $request->session()->forget(AuthFlowController::VERIFIED_SESSION_KEY);

        $auditLogger->log(actor: $user, subject: $user, action: 'auth.register', newValues: ['channel' => $channel->value]);

        $authenticator->establish($user, $request, method: 'register');

        /*
         * Wherever they were trying to get to, same as signing in.
         *
         * This used to always land on the dashboard, so a shopper who hit
         * Checkout with a full cart, registered, and was then dropped on the
         * dashboard had to find their way back and start the checkout again.
         * PostAuthRedirect only honours safe same-site paths, and falls back
         * to the dashboard when there was no particular destination.
         */
        $intended = PostAuthRedirect::customer($request);

        return redirect()->to($intended === route('home') ? route('dashboard') : $intended)
            ->with('success', 'Welcome to FirstMaket!');
    }
}
