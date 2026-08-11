<?php

use App\Modules\Cart\Controllers\CartController;
use Illuminate\Support\Facades\Route;

// Routes for the Cart module are auto-loaded on the customer/vendor-facing
// domain by App\Providers\ModuleServiceProvider.

/*
 * Browsing and filling a cart needs no account — guests get a session cart
 * (App\Modules\Cart\Services\GuestCart) that CartService folds into their
 * real one on login. Items are addressed by product uuid, not cart-item id,
 * so the guest and signed-in paths share one shape and a shopper can only
 * ever reach lines in their own cart.
 */
Route::get('cart', [CartController::class, 'index'])->name('cart.index');

/*
 * Throttled because these are the only writes in the app a stranger can make.
 * A signed-in shopper's abuse is attributable and bounded by their account;
 * an anonymous one is neither, and each request here creates or updates a
 * row. The ceiling is far above real shopping — nobody adds sixty items a
 * minute by hand — so it costs a genuine customer nothing.
 */
Route::middleware('throttle:60,1')->group(function () {
    Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
    Route::patch('cart/items/{product:uuid}', [CartController::class, 'update'])->name('cart.items.update');
    Route::delete('cart/items/{product:uuid}', [CartController::class, 'destroy'])->name('cart.items.destroy');
});

// Paying is where an account becomes mandatory: orders, savings debits and
// savings goals all hang off a user.
Route::middleware('auth')->group(function () {
    // Pay-in-full checkout: whole cart, address and payment method here.
    Route::get('cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');
    Route::post('cart/checkout', [CartController::class, 'checkoutStore'])->name('cart.checkout.store');
    // Saves the address on its own, so it survives an abandoned checkout.
    Route::post('cart/checkout/address', [CartController::class, 'saveAddress'])->name('cart.checkout.address');

    /*
     * Promo codes. Throttled because a code is short, printed on flyers and
     * therefore guessable by design — the limit, not secrecy, is what stops
     * somebody grinding through the keyspace looking for a live one. Ten a
     * minute is far more than a shopper with a code in hand needs.
     */
    Route::post('cart/promo', [CartController::class, 'applyPromo'])
        ->middleware('throttle:10,1')->name('cart.promo.apply');
    Route::delete('cart/promo', [CartController::class, 'removePromo'])->name('cart.promo.remove');
});
