<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Customer/vendor-facing dashboard on the main app domain. Staff dashboards
 * (Admin, Support, Logistics, Finance) live on the isolated admin subdomain
 * — see App\Http\Controllers\Admin\StaffDashboardController.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return match (true) {
            $user->hasRole('Vendor') => Inertia::render('Vendor/Dashboard'),
            $user->hasRole('Customer') => Inertia::render('Customer/Dashboard'),
            default => throw new HttpException(403, 'This account has no customer/vendor dashboard access.'),
        };
    }
}
