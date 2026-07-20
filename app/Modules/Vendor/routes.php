<?php

use App\Modules\Vendor\Controllers\VendorRegistrationController;
use Illuminate\Support\Facades\Route;

// Auto-loaded on the customer/vendor-facing domain by
// App\Providers\ModuleServiceProvider.

Route::middleware('guest')->group(function () {
    Route::get('vendor/register', [VendorRegistrationController::class, 'create'])->name('vendor.register');
    Route::post('vendor/register', [VendorRegistrationController::class, 'store']);
});
