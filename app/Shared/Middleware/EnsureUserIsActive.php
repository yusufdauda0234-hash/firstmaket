<?php

namespace App\Shared\Middleware;

use App\Shared\Enums\UserStatus;
use App\Shared\Security\AdminDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces "revoke active sessions on user suspension or ban"
 * (docs/firstmarket_Implementation_Plan.md Sprint 2): any request carrying a
 * session for a Suspended/Banned user is logged out and the session
 * destroyed, so existing sessions die the moment the status changes,
 * regardless of session driver. Also cancels Sanctum tokens for the /api
 * surface when one is in play.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && in_array($user->status, [UserStatus::Suspended, UserStatus::Banned], true)) {
            $user->tokens()->delete();

            auth()->guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()
                ->route(AdminDomain::matches($request) ? 'admin.login' : 'login')
                ->withErrors(['email' => 'Your account has been '.$user->status->value.'. Contact support for assistance.']);
        }

        return $next($request);
    }
}
