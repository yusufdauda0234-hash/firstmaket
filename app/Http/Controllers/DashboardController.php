<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The `/dashboard` route redirects to where each account type actually lives:
 * customers to the marketplace home (their dashboard), vendors to the Vendor
 * Center subdomain. Staff dashboards live on the isolated admin subdomain —
 * see App\Http\Controllers\Admin\StaffDashboardController.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        return match (true) {
            $user->hasRole('Vendor') => redirect()->route('vendor.dashboard'),
            // Customers have no separate dashboard — the marketplace home IS
            // their dashboard (Section 3 behavior). Any lingering /dashboard
            // link just lands them home.
            $user->hasRole('Customer') => redirect()->route('home'),
            default => throw new HttpException(403, 'This account has no customer/vendor dashboard access.'),
        };
    }
}
