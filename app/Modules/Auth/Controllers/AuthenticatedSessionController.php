<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Services\PostAuthRedirect;
use App\Modules\Auth\Services\SessionAuthenticator;
use App\Shared\Security\AdminDomain;
use App\Shared\Security\TwoFactorDevices;
use App\Shared\Security\VendorDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        return match (true) {
            AdminDomain::matches($request) => Inertia::render('Admin/Auth/Login'),
            VendorDomain::matches($request) => Inertia::render('Auth/VendorLogin', [
                'registered' => $request->boolean('registered'),
            ]),
            // Customers have no standalone login page — the storefront opens
            // the sign-in/register modal instead (?auth=login is picked up by
            // PublicLayout). The intended URL stays in the session, so the
            // post-login redirect still works.
            default => redirect()->route('home', ['auth' => 'login']),
        };
    }

    public function store(
        LoginRequest $request,
        SessionAuthenticator $authenticator,
        TwoFactorDevices $devices,
    ): RedirectResponse {
        $request->authenticate();

        // The password was right, but for an enrolled account that is only the
        // first factor. Hand off to the challenge before the session becomes a
        // real sign-in — see TwoFactorChallengeController.
        if ($this->needsTwoFactorChallenge($request, $devices)) {
            return $this->beginTwoFactorChallenge($request);
        }

        $request->session()->regenerate();

        $authenticator->recordLogin($request->user(), $request, method: 'password');

        return redirect()->intended(match (true) {
            AdminDomain::matches($request) => route('admin.dashboard'),
            VendorDomain::matches($request) => route('vendor.dashboard'),
            // Customers stay where they were (home is their dashboard).
            default => PostAuthRedirect::customer($request),
        });
    }

    /**
     * Is a second factor owed for this sign-in?
     *
     * Only for accounts that have actually enrolled, and only on the admin
     * portal — customers and vendors have no 2FA, so asking them for a code
     * would be a dead end.
     */
    private function needsTwoFactorChallenge(Request $request, TwoFactorDevices $devices): bool
    {
        $user = $request->user();

        if ($user === null || $user->two_factor_confirmed_at === null) {
            return false;
        }

        if (! AdminDomain::matches($request)) {
            return false;
        }

        return ! $devices->isTrusted($user, $request);
    }

    /**
     * Park the sign-in and send them to the code form.
     *
     * The guard is logged out again first: until the code is verified this must
     * grant nothing, so the only thing carried forward is the user's id in a
     * freshly rotated session.
     */
    private function beginTwoFactorChallenge(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;
        $remember = $request->boolean('remember');

        Auth::guard('web')->logout();
        $request->session()->regenerate();

        $request->session()->put(TwoFactorChallengeController::PENDING_USER, $userId);
        $request->session()->put(TwoFactorChallengeController::PENDING_REMEMBER, $remember);

        return redirect()->route('admin.two-factor.challenge');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $loginRoute = match (true) {
            AdminDomain::matches($request) => route('admin.login'),
            VendorDomain::matches($request) => route('vendor.login'),
            default => route('login'),
        };

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($loginRoute);
    }
}
