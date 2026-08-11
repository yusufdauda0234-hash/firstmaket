<?php

use App\Modules\Returns\Controllers\VendorReturnController;
use Illuminate\Support\Facades\Route;

/*
 * Vendor Center returns queue. Required from routes/vendors.php inside the
 * approval guard.
 *
 * A vendor reports facts about what physically came back, and may say the
 * condition is not what was described — but never decides the outcome. The
 * vendor is the party who loses the sale, so the decision belongs to an admin.
 */

Route::get('returns', [VendorReturnController::class, 'index'])->name('vendor.returns.index');

Route::post('returns/{return:uuid}/received', [VendorReturnController::class, 'markReceived'])
    ->middleware('throttle:20,1')
    ->name('vendor.returns.received');

Route::post('returns/{return:uuid}/contest', [VendorReturnController::class, 'contest'])
    ->middleware('throttle:20,1')
    ->name('vendor.returns.contest');
