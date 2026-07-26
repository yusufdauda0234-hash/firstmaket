<?php

use App\Modules\Identity\Controllers\PhoneVerificationController;
use Illuminate\Support\Facades\Route;

// Auto-loaded on the customer/vendor-facing domain by
// App\Providers\ModuleServiceProvider.

Route::middleware('auth')->group(function () {
    Route::post('phone/verify', [PhoneVerificationController::class, 'verify'])->name('phone.verify');
    Route::post('phone/send', [PhoneVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('phone.send');
});
