<?php

use App\Modules\Cart\Controllers\CartController;
use Illuminate\Support\Facades\Route;

// Routes for the Cart module are auto-loaded on the customer/vendor-facing
// domain by App\Providers\ModuleServiceProvider.

Route::middleware('auth')->group(function () {
    Route::get('cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
    Route::patch('cart/items/{cartItem}', [CartController::class, 'update'])->name('cart.items.update');
    Route::delete('cart/items/{cartItem}', [CartController::class, 'destroy'])->name('cart.items.destroy');

    // Pay-in-full checkout: whole cart, one wallet debit, address collected here.
    Route::get('cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');
    Route::post('cart/checkout', [CartController::class, 'checkoutStore'])->name('cart.checkout.store');

    // Bundle two or more selected items into one multi-product savings plan.
    Route::post('cart/bundle-plan/setup', [CartController::class, 'bundlePlanSetup'])->name('cart.bundle-plan.setup');
    Route::post('cart/bundle-plan', [CartController::class, 'bundlePlanStore'])->name('cart.bundle-plan.store');
});
