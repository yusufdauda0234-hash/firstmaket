<?php

use App\Modules\Auth\Controllers\AuthenticatedSessionController;
use App\Modules\Vendor\Controllers\EarningsController;
use App\Modules\Vendor\Controllers\VendorDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vendor Center Subdomain Routes
|--------------------------------------------------------------------------
|
| Served only on config('app.vendor_domain') (see bootstrap/app.php) with a
| session cookie scoped separately from the customer app
| (App\Shared\Middleware\ScopeAdminSessionCookie). Only vendor accounts may
| hold a session here — App\Shared\Middleware\EnsureCorrectPortal logs
| everyone else out, and the LoginRequest portal guard blocks them at
| sign-in. Vendor registration stays on the main site (/vendor/register)
| because the phone/email verification flows live there.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('vendor.login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('vendor.logout');

    Route::get('/', fn () => redirect()->route('vendor.dashboard'));
    Route::get('dashboard', VendorDashboardController::class)->name('vendor.dashboard');

    require app_path('Modules/Catalog/vendor-routes.php');
    require app_path('Modules/Orders/vendor-routes.php');

    // Earnings, payout history, and the verified payout bank account.
    Route::get('earnings', [EarningsController::class, 'show'])->name('vendor.earnings');
    Route::post('earnings/bank-account', [EarningsController::class, 'setBankAccount'])
        ->name('vendor.earnings.bank-account');
});
