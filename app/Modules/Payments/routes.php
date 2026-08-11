<?php

use App\Modules\Payments\Controllers\PaymentCallbackController;
use App\Modules\Payments\Controllers\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

// Routes for the Payments module are auto-loaded on the customer/vendor-facing
// domain by App\Providers\ModuleServiceProvider.

Route::middleware('auth')->group(function () {
    Route::get('savings/callback', [PaymentCallbackController::class, 'show'])->name('payment.callback');
});

/*
 * Paystack webhook — public, no auth, CSRF-exempt (see bootstrap/app.php).
 * Signature-verified inside the controller before anything is processed.
 *
 * Throttled despite being a machine-to-machine endpoint. It is the only
 * unauthenticated route in the app that writes a row per request, so without
 * a ceiling anybody who knows the URL can grow the events table until the
 * disk fills. The limit sits far above Paystack's real burst rate — a busy
 * minute is single-digit events, and a retry storm is bounded by their own
 * backoff — so a legitimate call is never refused.
 */
Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('webhooks.paystack');
