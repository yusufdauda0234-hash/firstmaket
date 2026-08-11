<?php

use App\Modules\Returns\Controllers\ReturnController;
use Illuminate\Support\Facades\Route;

/*
 * Customer-facing return routes, auto-loaded on the storefront domain by
 * App\Providers\ModuleServiceProvider.
 *
 * The vendor side lives in vendor-routes.php (required from routes/vendors.php
 * behind the approval guard), and the admin side — approving, rejecting and
 * the refund itself — lives in the Admin module on the isolated staff
 * subdomain. Moving money is staff work and does not share a domain with the
 * storefront.
 */

Route::middleware('auth')->group(function () {
    Route::get('account/returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('account/returns/{return:uuid}', [ReturnController::class, 'show'])->name('returns.show');

    // Opening a case writes a row and can carry photo uploads, and nothing
    // legitimate needs to do it in bulk.
    Route::post('orders/{order:uuid}/returns', [ReturnController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('returns.store');

    Route::post('account/returns/{return:uuid}/cancel', [ReturnController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('returns.cancel');

    Route::post('account/returns/{return:uuid}/shipped', [ReturnController::class, 'markShipped'])
        ->middleware('throttle:10,1')
        ->name('returns.shipped');
});
