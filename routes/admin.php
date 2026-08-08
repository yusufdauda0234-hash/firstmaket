<?php

use App\Http\Controllers\Admin\StaffDashboardController;
use App\Modules\Admin\Controllers\TwoFactorController;
use App\Modules\Auth\Controllers\AuthenticatedSessionController;
use App\Modules\Auth\Controllers\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Subdomain Routes
|--------------------------------------------------------------------------
|
| Served only on config('app.admin_domain') (see bootstrap/app.php) with a
| session cookie scoped separately from the customer app
| (App\Shared\Middleware\ScopeAdminSessionCookie). Administrator, Super
| Administrator, and Finance Officer accounts must also complete 2FA
| enrollment before reaching anything past two-factor/setup
| (App\Shared\Middleware\EnsureTwoFactorEnrolled).
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('admin.login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // Second step of a staff sign-in. Reachable only as a guest, because the
    // account is deliberately not authenticated until the code is verified —
    // the half-finished sign-in is nothing more than a user id in the session.
    Route::get('two-factor/challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('admin.two-factor.challenge');
    Route::post('two-factor/challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('admin.two-factor.challenge.store');
    Route::post('two-factor/challenge/abandon', [TwoFactorChallengeController::class, 'destroy'])
        ->name('admin.two-factor.challenge.abandon');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('admin.logout');

    Route::get('two-factor/setup', [TwoFactorController::class, 'setup'])->name('admin.two-factor.setup');
    Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('admin.two-factor.confirm');
    Route::get('two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
        ->name('admin.two-factor.recovery-codes');
    Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->name('admin.two-factor.recovery-codes.regenerate');

    Route::middleware('two_factor.enrolled')->group(function () {
        Route::get('/', StaffDashboardController::class)->name('admin.dashboard');

        require app_path('Modules/Admin/routes.php');
    });
});
