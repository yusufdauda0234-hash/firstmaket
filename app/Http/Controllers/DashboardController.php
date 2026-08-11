<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
            //
            // Staff accounts browsing the storefront land here too: the header
            // and account dropdown offer "Dashboard" to every signed-in user,
            // and their admin dashboard is on the admin subdomain behind a
            // separate session, so it is not reachable from this redirect.
            // Home rather than a 403 — this route only ever routes, and a nav
            // link that answers with an error page is a dead end, not a guard.
            // Nothing privileged is exposed by it: home is a public page.
            default => redirect()->route('home'),
        };
    }
}
