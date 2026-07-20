<?php

use App\Modules\Identity\Controllers\IdentityVerificationController;
use App\Modules\Identity\Controllers\PhoneVerificationController;
use Illuminate\Support\Facades\Route;

// Auto-loaded on the customer/vendor-facing domain by
// App\Providers\ModuleServiceProvider.

Route::middleware('auth')->group(function () {
    Route::get('phone/verify', [PhoneVerificationController::class, 'show'])->name('phone.verify.notice');
    Route::post('phone/verify', [PhoneVerificationController::class, 'verify'])->name('phone.verify');
    Route::post('phone/resend', [PhoneVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('phone.resend');

    Route::get('identity', [IdentityVerificationController::class, 'show'])->name('identity.status');
    Route::post('identity/bvn', [IdentityVerificationController::class, 'storeBvn'])
        ->middleware('throttle:6,1')
        ->name('identity.bvn');
    Route::post('identity/nin', [IdentityVerificationController::class, 'storeNin'])
        ->middleware('throttle:6,1')
        ->name('identity.nin');
});
