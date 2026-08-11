<?php

use App\Modules\Auth\Controllers\AuthenticatedSessionController;
use App\Modules\Vendor\Controllers\EarningsController;
use App\Modules\Vendor\Controllers\VendorDashboardController;
use App\Modules\Vendor\Controllers\VendorPasswordResetController;
use App\Modules\Auth\Controllers\ProfileController;
use App\Shared\Middleware\EnsureVendorApproved;
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

    /*
     * A vendor asking for their own reset, rather than having to phone
     * support and ask staff to send one.
     *
     * Throttled harder than the reset itself: it sends mail to an address
     * chosen by whoever is asking, so without a ceiling it is a way to have
     * FirstMaket flood a stranger's inbox.
     */
    Route::get('forgot-password', [VendorPasswordResetController::class, 'request'])
        ->name('vendor.password.request');
    Route::post('forgot-password', [VendorPasswordResetController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('vendor.password.email');

    // Where the "set your password" email lands. Guest-only: a vendor already
    // signed in has no business on it, and the token is the credential here.
    Route::get('reset-password/{token}', [VendorPasswordResetController::class, 'edit'])
        ->name('vendor.password.reset');
    Route::post('reset-password', [VendorPasswordResetController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('vendor.password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('vendor.logout');
    Route::get('profile', [ProfileController::class, 'vendor'])->name('vendor.profile');
    Route::put('profile', [ProfileController::class, 'updateVendor'])->name('vendor.profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('vendor.profile.password');

    Route::get('/', fn () => redirect()->route('vendor.dashboard'));

    // Reachable while pending: it is where a vendor is told what is happening,
    // and a portal that redirected every route including the one explaining
    // why would be a loop. Phone verification stays open too — it is part of
    // getting approved, not something that waits on approval.
    Route::get('dashboard', VendorDashboardController::class)->name('vendor.dashboard');
    require app_path('Modules/Identity/vendor-routes.php');

    /*
     * Everything a vendor can only do once somebody has said yes. Guarded in
     * one place rather than per controller — approval used to be checked
     * inside product management and nowhere else, so a pending vendor got a
     * full navigation and found out which pages worked by clicking them.
     */
    Route::middleware(EnsureVendorApproved::class)->group(function () {
        require app_path('Modules/Catalog/vendor-routes.php');
        require app_path('Modules/Orders/vendor-routes.php');
        require app_path('Modules/Returns/vendor-routes.php');

        // Earnings, payout history, and the verified payout bank account.
        Route::get('earnings', [EarningsController::class, 'show'])->name('vendor.earnings');
        Route::post('earnings/bank-account', [EarningsController::class, 'setBankAccount'])
            ->name('vendor.earnings.bank-account');
    });
});
