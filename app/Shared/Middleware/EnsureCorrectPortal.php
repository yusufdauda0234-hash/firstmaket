<?php

namespace App\Shared\Middleware;

use App\Shared\Enums\UserType;
use App\Shared\Security\AdminDomain;
use App\Shared\Security\VendorDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Companion to the wrong-portal login guard in LoginRequest: that guard
 * stops new logins on the wrong domain, this one cleans up any session that
 * is already on the wrong domain (e.g. created before the guard existed).
 * Staff belong on the admin subdomain, vendor accounts on the Vendor Center
 * or the main site (vendors also shop), everyone else on the main site
 * only. The user is logged out and lands on the correct login page with a
 * clear message instead of a dashboard full of 403s.
 */
class EnsureCorrectPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $isAdminPortal = AdminDomain::matches($request);
        $isVendorPortal = VendorDomain::matches($request);
        $isStaff = $user->user_type === UserType::Staff;
        $isVendor = $user->user_type === UserType::Vendor;

        $allowedHere = match (true) {
            $isAdminPortal => $isStaff,
            $isVendorPortal => $isVendor,
            default => ! $isStaff,
        };

        if ($allowedHere) {
            return $next($request);
        }

        auth()->guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        [$loginRoute, $message] = match (true) {
            $isAdminPortal => ['admin.login', 'This area is for FirstMarket staff only. Customer and vendor accounts sign in on the main FirstMarket site.'],
            $isVendorPortal => ['vendor.login', 'The Vendor Center is for approved vendor accounts only. Shoppers sign in on the main FirstMarket site.'],
            default => ['login', 'Staff accounts sign in through the staff portal, not the customer site. Please use your admin portal address.'],
        };

        return redirect()
            ->route($loginRoute)
            ->withErrors(['email' => $message, 'identifier' => $message]);
    }
}
