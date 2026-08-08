<?php

use App\Modules\Payments\Controllers\PaymentCallbackController;
use App\Modules\Payments\Controllers\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

// Routes for the Payments module are auto-loaded on the customer/vendor-facing
// domain by App\Providers\ModuleServiceProvider.

Route::middleware('auth')->group(function () {
    Route::get('savings/callback', [PaymentCallbackController::class, 'show'])->name('payment.callback');
});

// Paystack webhook — public, no auth, CSRF-exempt (see bootstrap/app.php).
// Signature-verified inside the controller before anything is processed.
Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle'])->name('webhooks.paystack');
