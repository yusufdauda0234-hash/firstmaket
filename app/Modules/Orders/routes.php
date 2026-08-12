<?php

use App\Modules\Orders\Controllers\OrderController;
use App\Modules\Orders\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

// Customer order routes, auto-loaded on the customer-facing domain by
// App\Providers\ModuleServiceProvider. The delivery address is captured
// created by checkout or by a savings goal reaching its target.
// Vendor-side preparation routes live in vendor-routes.php on the Vendor
// Center subdomain; staff routes live on the admin subdomain.

Route::middleware('auth')->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order:uuid}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order:uuid}/confirm-receipt', [OrderController::class, 'confirmReceipt'])
        ->name('orders.confirm-receipt');
    Route::post('orders/{order:uuid}/pay-goods', [OrderController::class, 'payGoods'])
        ->name('orders.pay-goods');

    // Receipts. One per checkout rather than per order, so they sit at their
    // own path instead of under a single order.
    Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::get('receipts/{receipt:uuid}', [ReceiptController::class, 'show'])->name('receipts.show');
});
