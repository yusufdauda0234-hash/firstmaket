<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Services\PostAuthRedirect;
use App\Modules\Auth\Services\SessionAuthenticator;
use App\Shared\Security\AdminDomain;
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
            VendorDomain::matches($request) => Inertia::render('Auth/VendorLogin'),
            // Customers have no standalone login page — the storefront opens
            // the sign-in/register modal instead (?auth=login is picked up by
            // PublicLayout). The intended URL stays in the session, so the
            // post-login redirect still works.
            default => redirect()->route('home', ['auth' => 'login']),
        };
    }

    public function store(LoginRequest $request, SessionAuthenticator $authenticator): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $authenticator->recordLogin($request->user(), $request, method: 'password');

        return redirect()->intended(match (true) {
            AdminDomain::matches($request) => route('admin.dashboard'),
            VendorDomain::matches($request) => route('vendor.dashboard'),
            // Customers stay where they were (home is their dashboard).
            default => PostAuthRedirect::customer($request),
        });
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
