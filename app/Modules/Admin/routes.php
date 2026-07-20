<?php

use App\Modules\Admin\Controllers\CommissionSettingsController;
use App\Modules\Admin\Controllers\CustomerLookupController;
use App\Modules\Admin\Controllers\DocumentDownloadController;
use App\Modules\Admin\Controllers\FeeSettingsController;
use App\Modules\Admin\Controllers\LogisticsOrderController;
use App\Modules\Admin\Controllers\OrderAdminController;
use App\Modules\Admin\Controllers\ProductApprovalController;
use App\Modules\Admin\Controllers\ReconciliationController;
use App\Modules\Admin\Controllers\SupportAdminController;
use App\Modules\Admin\Controllers\VendorApprovalController;
use App\Modules\Admin\Controllers\VendorPayoutController;
use Illuminate\Support\Facades\Route;

// Required directly from routes/admin.php inside the auth + 2FA-enrolled
// middleware group on the admin subdomain, so everything here inherits both
// automatically. Access is permission-based, never hard-coded by role name.

Route::middleware('permission:vendors.view')->group(function () {
    Route::get('vendors', [VendorApprovalController::class, 'index'])->name('admin.vendors.index');
    Route::get('vendors/{vendorProfile}/details', [VendorApprovalController::class, 'details'])->name('admin.vendors.details');
    Route::get('vendors/{vendorProfile}', [VendorApprovalController::class, 'show'])->name('admin.vendors.show');

    Route::get('documents/{uploadedDocument}', DocumentDownloadController::class)->name('admin.documents.download');
});

Route::middleware('permission:vendors.approve')->group(function () {
    Route::post('vendors/{vendorProfile}/approve', [VendorApprovalController::class, 'approve'])->name('admin.vendors.approve');
    Route::post('vendors/{vendorProfile}/reject', [VendorApprovalController::class, 'reject'])->name('admin.vendors.reject');
});

Route::middleware('permission:vendors.suspend')->group(function () {
    Route::post('vendors/{vendorProfile}/suspend', [VendorApprovalController::class, 'suspend'])->name('admin.vendors.suspend');
    Route::post('vendors/{vendorProfile}/reinstate', [VendorApprovalController::class, 'reinstate'])->name('admin.vendors.reinstate');
});

Route::middleware('permission:vendor_fees.manage')->group(function () {
    Route::get('settings/fees', [FeeSettingsController::class, 'edit'])->name('admin.settings.fees');
    Route::post('settings/fees', [FeeSettingsController::class, 'update'])->name('admin.settings.fees.update');
});

Route::middleware('permission:wallet.reconcile')->group(function () {
    Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('admin.reconciliation.index');
    Route::post('reconciliation', [ReconciliationController::class, 'store'])->name('admin.reconciliation.store');
    Route::get('reconciliation/{settlementImport}', [ReconciliationController::class, 'show'])->name('admin.reconciliation.show');
});

Route::middleware('permission:products.approve')->group(function () {
    Route::get('products', [ProductApprovalController::class, 'index'])->name('admin.products.index');
    Route::get('products/{product:uuid}/details', [ProductApprovalController::class, 'details'])->name('admin.products.details');
    Route::get('products/{product:uuid}', [ProductApprovalController::class, 'show'])->name('admin.products.show');
    Route::post('products/{product:uuid}/approve', [ProductApprovalController::class, 'approve'])->name('admin.products.approve');
    Route::post('products/{product:uuid}/reject', [ProductApprovalController::class, 'reject'])->name('admin.products.reject');
});

// ── Sprint 6: orders, logistics, commissions, vendor payouts ──

Route::middleware('permission:orders.manage')->group(function () {
    Route::get('orders', [OrderAdminController::class, 'index'])->name('admin.orders.index');
    Route::get('orders/{order:uuid}', [OrderAdminController::class, 'show'])->name('admin.orders.show');
    Route::post('orders/{order:uuid}/confirm', [OrderAdminController::class, 'confirm'])->name('admin.orders.confirm');
    Route::post('orders/{order:uuid}/assign-logistics', [OrderAdminController::class, 'assignLogistics'])->name('admin.orders.assign-logistics');
    Route::post('orders/{order:uuid}/resolve-rejection', [OrderAdminController::class, 'resolveRejection'])->name('admin.orders.resolve-rejection');
});

Route::middleware('permission:commissions.manage')->group(function () {
    Route::get('settings/commissions', [CommissionSettingsController::class, 'edit'])->name('admin.settings.commissions');
    Route::post('settings/commissions', [CommissionSettingsController::class, 'update'])->name('admin.settings.commissions.update');
});

Route::middleware('permission:delivery.update')->group(function () {
    Route::get('deliveries', [LogisticsOrderController::class, 'index'])->name('admin.deliveries.index');
    Route::post('deliveries/{order:uuid}/status', [LogisticsOrderController::class, 'updateStatus'])->name('admin.deliveries.update-status');
});

Route::middleware('permission:vendor_payouts.approve')->group(function () {
    Route::get('payouts', [VendorPayoutController::class, 'index'])->name('admin.payouts.index');
    Route::post('payouts/generate', [VendorPayoutController::class, 'generate'])->name('admin.payouts.generate');
    Route::get('payouts/{batch:uuid}', [VendorPayoutController::class, 'show'])->name('admin.payouts.show');
    Route::post('payouts/{batch:uuid}/approve', [VendorPayoutController::class, 'approve'])->name('admin.payouts.approve');
    Route::post('payouts/items/{item}/paid', [VendorPayoutController::class, 'markPaid'])->name('admin.payouts.items.paid');
    Route::post('payouts/items/{item}/failed', [VendorPayoutController::class, 'markFailed'])->name('admin.payouts.items.failed');
});

// ── Sprint 7: support agent workspace ──

Route::middleware('permission:support.manage')->group(function () {
    Route::get('support', [SupportAdminController::class, 'index'])->name('admin.support.index');
    Route::get('support/lookup', [CustomerLookupController::class, 'index'])->name('admin.support.lookup');
    Route::get('support/{ticket:uuid}', [SupportAdminController::class, 'show'])->name('admin.support.show');
    Route::post('support/{ticket:uuid}/reply', [SupportAdminController::class, 'reply'])->name('admin.support.reply');
    Route::post('support/{ticket:uuid}/status', [SupportAdminController::class, 'updateStatus'])->name('admin.support.status');
});
