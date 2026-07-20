<?php

use App\Modules\Auth\Controllers\AccountOverviewController;
use App\Modules\Auth\Controllers\AccountSettingsController;
use App\Modules\Auth\Controllers\AuthenticatedSessionController;
use App\Modules\Auth\Controllers\AuthFlowController;
use App\Modules\Auth\Controllers\EmailVerificationController;
use App\Modules\Auth\Controllers\RegisteredUserController;
use App\Modules\Auth\Controllers\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // Combined sign-in/register modal steps (Sprint 2 Addendum).
    Route::post('auth/identify', [AuthFlowController::class, 'identify'])
        ->middleware('throttle:20,1')->name('auth.identify');
    Route::post('auth/code/send', [AuthFlowController::class, 'sendCode'])
        ->middleware('throttle:6,1')->name('auth.code.send');
    Route::post('auth/code/verify', [AuthFlowController::class, 'verifyCode'])
        ->middleware('throttle:12,1')->name('auth.code.verify');
    Route::post('auth/code/login', [AuthFlowController::class, 'loginWithCode'])
        ->middleware('throttle:12,1')->name('auth.code.login');
    Route::post('auth/password/reset', [AuthFlowController::class, 'resetPassword'])
        ->middleware('throttle:6,1')->name('auth.password.reset');

    // Continue with Google / Facebook.
    Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('auth.social.redirect');
    Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('auth.social.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // "My Account" overview — the customer's account landing page.
    Route::get('account', [AccountOverviewController::class, 'show'])->name('account.overview');

    // Account settings (Sprint 2 Addendum): secondary identifier, password,
    // linked social accounts.
    Route::get('settings/account', [AccountSettingsController::class, 'show'])->name('account.settings');
    Route::post('settings/identifier/send-code', [AccountSettingsController::class, 'sendIdentifierCode'])
        ->middleware('throttle:6,1')->name('account.identifier.send');
    Route::post('settings/identifier/confirm', [AccountSettingsController::class, 'confirmIdentifier'])
        ->middleware('throttle:12,1')->name('account.identifier.confirm');
    Route::put('settings/password', [AccountSettingsController::class, 'updatePassword'])->name('account.password');
    Route::delete('settings/social/{provider}', [AccountSettingsController::class, 'unlinkSocial'])->name('account.social.unlink');
});
