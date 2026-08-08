<?php

use App\Modules\Orders\Controllers\VendorOrderController;
use Illuminate\Support\Facades\Route;

// Vendor order preparation — served only on the Vendor Center subdomain
// (required from routes/vendors.php inside its auth middleware group).
// Ownership is enforced in PreparationService against the vendor profile;
// customer identity is never exposed on these screens.

Route::prefix('orders')->group(function () {
    Route::get('/', [VendorOrderController::class, 'index'])->name('vendor.orders.index');
    Route::post('{order:uuid}/confirm-stock', [VendorOrderController::class, 'confirmStock'])->name('vendor.orders.confirm-stock');
    Route::post('bulk-ready', [VendorOrderController::class, 'bulkReady'])->name('vendor.orders.bulk-ready');
    Route::post('{order:uuid}/ready', [VendorOrderController::class, 'markReady'])->name('vendor.orders.ready');
    Route::post('{order:uuid}/reject', [VendorOrderController::class, 'reject'])->name('vendor.orders.reject');
});
