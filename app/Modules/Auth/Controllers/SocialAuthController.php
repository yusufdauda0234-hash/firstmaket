<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Models\SocialAccount;
use App\Modules\Auth\Services\SessionAuthenticator;
use App\Modules\Customer\Models\CustomerProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Continue with Google / Facebook" (Sprint 2 Addendum). Match order:
 *
 * 1. Known provider id → sign that user in.
 * 2. Provider-verified email matches an existing account → link and sign in
 *    (the provider attesting to the email is the ownership proof).
 * 3. Otherwise → create a Customer account with a verified email and no
 *    password (the user may set one later in settings).
 *
 * Staff accounts can never be reached through social login.
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'facebook'];

    public function __construct(private readonly SessionAuthenticator $authenticator) {}

    public function redirect(string $provider): SymfonyRedirectResponse
    {
        $this->assertSupported($provider);

        // Without credentials Google/Facebook would show a raw
        // "Missing required parameter: client_id" error page — fail with a
        // friendly message on our side instead.
        if (empty(config("services.{$provider}.client_id"))) {
            return redirect()->route('home')->with(
                'error',
                ucfirst($provider).' sign-in is not available yet. Please continue with your email or phone number instead.'
            );
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider, Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $this->assertSupported($provider);

        try {
            /** @var OAuthUser $oauthUser */
            $oauthUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect()->route('home')->with('error', 'Sign-in with '.ucfirst($provider).' was cancelled or failed. Please try again.');
        }

        $email = $oauthUser->getEmail();

        $user = DB::transaction(function () use ($provider, $oauthUser, $email) {
            $existingLink = SocialAccount::query()
                ->where('provider', $provider)
                ->where('provider_id', $oauthUser->getId())
                ->first();

            if ($existingLink !== null) {
                return $existingLink->user;
            }

            $user = $email !== null
                ? User::query()->where('email', mb_strtolower($email))->first()
                : null;

            if ($user !== null && $user->user_type === UserType::Staff) {
                return null;
            }

            if ($user === null) {
                if ($email === null) {
                    // No stable email from the provider and no existing link:
                    // we cannot create a contactable account.
                    return null;
                }

                $user = User::query()->create([
                    'name' => $oauthUser->getName() ?: 'FirstMaket Customer',
                    'email' => mb_strtolower($email),
                    'phone' => null,
                    'password' => null,
                    'user_type' => UserType::Customer,
                    'status' => UserStatus::Active,
                ]);

                // The provider vouches for this email; verified_at is
                // guarded, so stamp it explicitly.
                $user->forceFill(['email_verified_at' => now()])->save();

                $user->assignRole('Customer');

                CustomerProfile::query()->create(['user_id' => $user->id]);
            } elseif ($user->email_verified_at === null) {
                // The provider vouches for this email, which is exactly what
                // our own verification link would have proven.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            SocialAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $oauthUser->getId(),
                'provider_email' => mb_strtolower($email),
                'avatar_url' => $oauthUser->getAvatar(),
            ]);

            return $user;
        });

        if ($user === null) {
            return redirect()->route('home')->with('error', 'We could not sign you in with '.ucfirst($provider).'. Please register with your email or phone number instead.');
        }

        if (in_array($user->status, [UserStatus::Suspended, UserStatus::Banned], true)) {
            return redirect()->route('home')->with('error', 'Your account has been '.$user->status->value.'. Contact support for assistance.');
        }

        $auditLogger->log(actor: $user, subject: $user, action: 'auth.social_login', newValues: ['provider' => $provider]);

        $this->authenticator->establish($user, $request, method: $provider);

        // Land back on the marketplace, not a dashboard (home is the dashboard).
        return redirect()->intended(route('home'));
    }

    private function assertSupported(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new NotFoundHttpException;
        }
    }
}
