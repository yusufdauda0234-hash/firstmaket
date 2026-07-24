<?php

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administrator, Super Administrator, and Finance Officer accounts cannot
 * reach any other admin-subdomain route until 2FA is enrolled. Support
 * Agent and Logistics Personnel are excluded deliberately — only roles that
 * touch money, permissions, or platform-wide settings carry the mandatory
 * requirement (docs/FirstMaket_Security_Compliance.md section 3).
 */
class EnsureTwoFactorEnrolled
{
    private const REQUIRED_ROLES = [
        'Super Administrator',
        'Administrator',
        'Finance Officer',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user
            && $user->hasAnyRole(self::REQUIRED_ROLES)
            && ! $user->two_factor_confirmed_at
            && ! $request->routeIs('admin.two-factor.*')
            && ! $request->routeIs('admin.logout')
        ) {
            return Redirect::route('admin.two-factor.setup');
        }

        return $next($request);
    }
}
