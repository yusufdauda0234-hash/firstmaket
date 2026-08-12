<?php

use App\Modules\Affiliates\Controllers\AffiliateController;
use Illuminate\Support\Facades\Route;

/*
 * Public click-tracking entry point.
 *
 * Throttled as well as de-duplicated. The service already ignores a repeat
 * click from the same IP + user-agent inside the dedupe window, which stops an
 * affiliate inflating their own numbers by reloading — but that check runs
 * *after* a row is considered, and a caller rotating its user-agent defeats
 * the fingerprint entirely. Without a limit, an unauthenticated endpoint that
 * writes a row per request is an invitation to fill the table.
 *
 * The `s` query parameter is the link's HMAC. It always lands on our own home
 * route — never a destination read from the URL, which would make every
 * partner link an open redirect on the marketplace's domain.
 */
Route::get('a/{code}', [AffiliateController::class, 'capture'])
    ->middleware('throttle:30,1')
    ->name('affiliates.capture');

Route::middleware('auth')->group(function () {
    Route::get('account/affiliate', [AffiliateController::class, 'index'])->name('affiliates.index');
    Route::post('account/affiliate', [AffiliateController::class, 'apply'])->name('affiliates.apply');

    Route::post('account/affiliate/links', [AffiliateController::class, 'storeLink'])->name('affiliates.links.store');
    Route::delete('account/affiliate/links/{link}', [AffiliateController::class, 'destroyLink'])->name('affiliates.links.destroy');

    Route::post('account/affiliate/bank-account', [AffiliateController::class, 'storeBankAccount'])->name('affiliates.bank-account.store');

    // Applying for the next rank. Throttled because it accepts file uploads.
    Route::post('account/affiliate/upgrade', [AffiliateController::class, 'requestUpgrade'])
        ->middleware('throttle:6,1')
        ->name('affiliates.upgrade.request');
});
