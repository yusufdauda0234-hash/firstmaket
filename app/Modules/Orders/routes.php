<?php

use App\Modules\Orders\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// Customer order routes, auto-loaded on the customer-facing domain by
// App\Providers\ModuleServiceProvider. The delivery address is captured
// here — only after the plan is fully funded (OrderService enforces it).
// Vendor-side preparation routes live in vendor-routes.php on the Vendor
// Center subdomain; staff routes live on the admin subdomain.

Route::middleware('auth')->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{order:uuid}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order:uuid}/confirm-receipt', [OrderController::class, 'confirmReceipt'])
        ->name('orders.confirm-receipt');
});
