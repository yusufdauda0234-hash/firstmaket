<?php

use App\Modules\Referrals\Controllers\ReferralController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated, and it writes to the session — same reasoning as
// the affiliate capture route it mirrors.
Route::get('ref/{code}', [ReferralController::class, 'capture'])
    ->middleware('throttle:30,1')
    ->name('referrals.capture');

Route::middleware('auth')->group(function () {
	Route::get('account/referrals', [ReferralController::class, 'index'])->name('referrals.index');
});
