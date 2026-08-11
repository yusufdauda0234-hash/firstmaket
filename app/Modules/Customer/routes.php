<?php

use App\Modules\Customer\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

// Routes for the Customer module are registered here and auto-loaded on the
// customer/vendor-facing domain by App\Providers\ModuleServiceProvider.
// Keep controllers thin; delegate to Actions/Services (see
// docs/FirstMaket_Developer_Guidelines.md).

Route::middleware('auth')->group(function () {
	Route::get('account/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
	Route::post('account/wishlist/{product:uuid}', [WishlistController::class, 'store'])->name('wishlist.store');
	Route::delete('account/wishlist/{product:uuid}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');
	Route::put('account/wishlist/{product:uuid}/price-alert', [WishlistController::class, 'updatePriceAlert'])->name('wishlist.price-alert.update');
});
