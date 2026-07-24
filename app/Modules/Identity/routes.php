<?php

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
});
