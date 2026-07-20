<?php

use App\Modules\Catalog\Controllers\VendorProductController;
use Illuminate\Support\Facades\Route;

// Vendor listing management — served only on the Vendor Center subdomain
// (required from routes/vendors.php inside its auth middleware group).
// Approved-vendor status is enforced in the controller against the vendor
// profile.

Route::prefix('products')->group(function () {
    Route::get('/', [VendorProductController::class, 'index'])->name('vendor.products.index');
    Route::get('create', [VendorProductController::class, 'create'])->name('vendor.products.create');
    Route::post('/', [VendorProductController::class, 'store'])->name('vendor.products.store');
    Route::get('{product:uuid}/details', [VendorProductController::class, 'details'])->name('vendor.products.details');
    Route::get('{product:uuid}/edit', [VendorProductController::class, 'edit'])->name('vendor.products.edit');
    Route::post('{product:uuid}', [VendorProductController::class, 'update'])->name('vendor.products.update');
    Route::post('{product:uuid}/submit', [VendorProductController::class, 'submit'])->name('vendor.products.submit');
});
