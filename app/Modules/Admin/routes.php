<?php

use App\Modules\Admin\Controllers\AiSettingsController;
use App\Modules\Admin\Controllers\CategoryController;
use App\Modules\Admin\Controllers\CommissionSettingsController;
use App\Modules\Admin\Controllers\ContentPageController;
use App\Modules\Admin\Controllers\CustomerLookupController;
use App\Modules\Admin\Controllers\DeliveryRateController;
use App\Modules\Admin\Controllers\DisplayCurrencyController;
use App\Modules\Admin\Controllers\DocumentDownloadController;
use App\Modules\Admin\Controllers\FeeSettingsController;
use App\Modules\Admin\Controllers\GuideController;
use App\Modules\Admin\Controllers\OrderAdminController;
use App\Modules\Admin\Controllers\PhoneReviewController;
use App\Modules\Admin\Controllers\PlanTermController;
use App\Modules\Admin\Controllers\ProductApprovalController;
use App\Modules\Admin\Controllers\ProductAttributeController;
use App\Modules\Admin\Controllers\PromoCodeController;
use App\Modules\Admin\Controllers\ReconciliationController;
use App\Modules\Admin\Controllers\ReportingController;
use App\Modules\Admin\Controllers\StaffController;
use App\Modules\Admin\Controllers\SupportAdminController;
use App\Modules\Admin\Controllers\UserManagementController;
use App\Modules\Admin\Controllers\VendorApprovalController;
use App\Modules\Admin\Controllers\VendorPayoutController;
use App\Modules\Logistics\Controllers\CashController;
use App\Modules\Logistics\Controllers\CourierTaskController;
use App\Modules\Logistics\Controllers\DispatchController;
use Illuminate\Support\Facades\Route;

// Required directly from routes/admin.php inside the auth + 2FA-enrolled
// middleware group on the admin subdomain, so everything here inherits both
// automatically. Access is permission-based, never hard-coded by role name.

// The admin manual. No permission gate — every staff member should be able
// to read how the workspace works; the sections themselves are filtered to
// what the reader can actually open.
Route::get('guide', GuideController::class)->name('admin.guide');

Route::middleware('permission:vendors.view')->group(function () {
    Route::get('vendors', [VendorApprovalController::class, 'index'])->name('admin.vendors.index');
    Route::get('vendors/{vendorProfile}/details', [VendorApprovalController::class, 'details'])->name('admin.vendors.details');
    Route::get('vendors/{vendorProfile}', [VendorApprovalController::class, 'show'])->name('admin.vendors.show');

    Route::get('documents/{uploadedDocument}', DocumentDownloadController::class)->name('admin.documents.download');
});

Route::middleware('permission:identity.review')->group(function () {
    Route::get('phone-numbers', [PhoneReviewController::class, 'index'])->name('admin.phone.index');
    Route::post('phone-numbers/{user:uuid}/approve', [PhoneReviewController::class, 'approve'])->name('admin.phone.approve');
    Route::post('phone-numbers/{user:uuid}/reject', [PhoneReviewController::class, 'reject'])->name('admin.phone.reject');
});

Route::middleware('permission:vendors.approve')->group(function () {
    // Onboarding a seller directly is at least as privileged as approving one
    // who applied, so it sits behind the same permission.
    Route::post('vendors', [VendorApprovalController::class, 'store'])->name('admin.vendors.store');

    // Bulk approve/reject from the list's checkbox selection. Each item still
    // goes through VendorApprovalService one at a time.
    Route::post('vendors/bulk', [VendorApprovalController::class, 'bulkUpdate'])->name('admin.vendors.bulk');

    // Account recovery for a seller who never got their first code, or locked
    // themselves out. Staff never see the code and never set a password.
    Route::post('vendors/{vendorProfile}/password-reset', [VendorApprovalController::class, 'sendPasswordReset'])
        ->middleware('throttle:10,1')
        ->name('admin.vendors.password-reset');

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

// Pay Small Small terms: what cadences and instalment counts customers may
// choose at checkout. Gated on the same permission as the fee settings —
// both decide what the business charges.
Route::middleware('permission:vendor_fees.manage')->group(function () {
    Route::get('settings/plan-terms', [PlanTermController::class, 'index'])->name('admin.settings.plan-terms');
    Route::post('settings/plan-terms', [PlanTermController::class, 'store'])->name('admin.settings.plan-terms.store');
    // Before {planTerm}, or 'template' binds as a term id.
    Route::post('settings/plan-terms/template', [PlanTermController::class, 'applyTemplate'])->name('admin.settings.plan-terms.template');
    Route::post('settings/plan-terms/bulk', [PlanTermController::class, 'bulkUpdate'])->name('admin.settings.plan-terms.bulk');
    Route::put('settings/plan-terms/{planTerm}', [PlanTermController::class, 'update'])->name('admin.settings.plan-terms.update');
    Route::delete('settings/plan-terms/{planTerm}', [PlanTermController::class, 'destroy'])->name('admin.settings.plan-terms.destroy');
});

// What delivery costs, per state and per leg. Same permission as the other
// settings that decide what a customer is charged.
Route::middleware('permission:vendor_fees.manage')->group(function () {
    Route::get('settings/delivery-rates', [DeliveryRateController::class, 'index'])->name('admin.settings.delivery-rates');
    Route::post('settings/delivery-rates', [DeliveryRateController::class, 'store'])->name('admin.settings.delivery-rates.store');
    Route::post('settings/delivery-rates/template', [DeliveryRateController::class, 'applyTemplate'])->name('admin.settings.delivery-rates.template');
    // Before {deliveryRate}, otherwise 'bulk' is bound as a rate uuid.
    Route::post('settings/delivery-rates/bulk', [DeliveryRateController::class, 'bulkUpdate'])->name('admin.settings.delivery-rates.bulk');
    Route::put('settings/delivery-rates/{deliveryRate:uuid}', [DeliveryRateController::class, 'update'])->name('admin.settings.delivery-rates.update');
    Route::delete('settings/delivery-rates/{deliveryRate:uuid}', [DeliveryRateController::class, 'destroy'])->name('admin.settings.delivery-rates.destroy');
});

// Display currencies and their rates. These figures are quoted to shoppers, so
// they sit behind the same permission as anything else that decides what a
// customer sees as a price.
Route::middleware('permission:vendor_fees.manage')->group(function () {
    Route::get('settings/currencies', [DisplayCurrencyController::class, 'index'])->name('admin.settings.currencies');
    Route::post('settings/currencies', [DisplayCurrencyController::class, 'store'])->name('admin.settings.currencies.store');
    Route::post('settings/currencies/template', [DisplayCurrencyController::class, 'applyTemplate'])->name('admin.settings.currencies.template');
    Route::put('settings/currencies/{displayCurrency}', [DisplayCurrencyController::class, 'update'])->name('admin.settings.currencies.update');
    Route::delete('settings/currencies/{displayCurrency}', [DisplayCurrencyController::class, 'destroy'])->name('admin.settings.currencies.destroy');
});

Route::middleware('permission:savings.reconcile')->group(function () {
    Route::get('reconciliation', [ReconciliationController::class, 'index'])->name('admin.reconciliation.index');
    Route::post('reconciliation', [ReconciliationController::class, 'store'])->name('admin.reconciliation.store');
    Route::get('reconciliation/{settlementImport}', [ReconciliationController::class, 'show'])->name('admin.reconciliation.show');
});

// The catalogue tree and the fields vendors fill in when listing. Both
// shape what every vendor sees, so they sit behind one permission.
Route::middleware('permission:catalog.manage')->group(function () {
    Route::get('catalog/categories', [CategoryController::class, 'index'])->name('admin.catalog.categories');
    Route::post('catalog/categories', [CategoryController::class, 'store'])->name('admin.catalog.categories.store');
    Route::post('catalog/categories/bulk', [CategoryController::class, 'bulkUpdate'])->name('admin.catalog.categories.bulk');
    Route::put('catalog/categories/{category}', [CategoryController::class, 'update'])->name('admin.catalog.categories.update');
    Route::delete('catalog/categories/{category}', [CategoryController::class, 'destroy'])->name('admin.catalog.categories.destroy');

    Route::get('catalog/product-fields', [ProductAttributeController::class, 'index'])->name('admin.catalog.fields');
    Route::post('catalog/product-fields', [ProductAttributeController::class, 'store'])->name('admin.catalog.fields.store');
    Route::post('catalog/product-fields/bulk', [ProductAttributeController::class, 'bulkUpdate'])->name('admin.catalog.fields.bulk');
    // Before {productAttribute}, or 'template' binds as a field id.
    Route::post('catalog/product-fields/template', [ProductAttributeController::class, 'applyTemplate'])->name('admin.catalog.fields.template');
    Route::put('catalog/product-fields/{productAttribute}', [ProductAttributeController::class, 'update'])->name('admin.catalog.fields.update');
    Route::delete('catalog/product-fields/{productAttribute}', [ProductAttributeController::class, 'destroy'])->name('admin.catalog.fields.destroy');
});

Route::middleware('permission:products.approve')->group(function () {
    Route::get('products', [ProductApprovalController::class, 'index'])->name('admin.products.index');
    Route::get('products/{product:uuid}/details', [ProductApprovalController::class, 'details'])->name('admin.products.details');
    Route::get('products/{product:uuid}', [ProductApprovalController::class, 'show'])->name('admin.products.show');
    // Registered before the {product:uuid} routes so "bulk" is never mistaken
    // for a product identifier.
    Route::post('products/bulk', [ProductApprovalController::class, 'bulkUpdate'])->name('admin.products.bulk');

    Route::post('products/{product:uuid}/approve', [ProductApprovalController::class, 'approve'])->name('admin.products.approve');
    Route::post('products/{product:uuid}/reject', [ProductApprovalController::class, 'reject'])->name('admin.products.reject');
});

// ── Sprint 6: orders, logistics, commissions, vendor payouts ──

Route::middleware('permission:orders.manage')->group(function () {
    Route::get('orders', [OrderAdminController::class, 'index'])->name('admin.orders.index');
    Route::get('orders/{order:uuid}', [OrderAdminController::class, 'show'])->name('admin.orders.show');
    Route::post('orders/bulk-confirm', [OrderAdminController::class, 'bulkConfirm'])->name('admin.orders.bulk-confirm');
    Route::post('orders/{order:uuid}/confirm', [OrderAdminController::class, 'confirm'])->name('admin.orders.confirm');
    Route::post('orders/{order:uuid}/assign-logistics', [OrderAdminController::class, 'assignLogistics'])->name('admin.orders.assign-logistics');
    Route::post('orders/{order:uuid}/resolve-rejection', [OrderAdminController::class, 'resolveRejection'])->name('admin.orders.resolve-rejection');
});

Route::middleware('permission:commissions.manage')->group(function () {
    Route::get('settings/commissions', [CommissionSettingsController::class, 'edit'])->name('admin.settings.commissions');
    Route::post('settings/commissions', [CommissionSettingsController::class, 'store'])->name('admin.settings.commissions.store');
    Route::put('settings/commissions/{commissionRule:uuid}', [CommissionSettingsController::class, 'update'])->name('admin.settings.commissions.update');
    Route::delete('settings/commissions/{commissionRule:uuid}', [CommissionSettingsController::class, 'destroy'])->name('admin.settings.commissions.destroy');

    /*
     * Promo codes sit behind the same permission as commissions, because that
     * is what funds them: a discount comes out of FirstMaket's cut, so
     * whoever may set the cut may spend it. No delete — a used code is only
     * ever switched off, so its redemption history survives.
     */
    Route::get('settings/promo-codes', [PromoCodeController::class, 'index'])->name('admin.settings.promo-codes');
    Route::post('settings/promo-codes', [PromoCodeController::class, 'store'])->name('admin.settings.promo-codes.store');
    Route::post('settings/promo-codes/template', [PromoCodeController::class, 'applyTemplate'])->name('admin.settings.promo-codes.template');
    Route::put('settings/promo-codes/{promoCode:uuid}', [PromoCodeController::class, 'update'])->name('admin.settings.promo-codes.update');
    Route::delete('settings/promo-codes/{promoCode:uuid}', [PromoCodeController::class, 'destroy'])->name('admin.settings.promo-codes.destroy');
});

/*
 * The courier's own screen. Everything is scoped to their live assignments,
 * so a parcel they are not carrying is not in the query rather than being
 * refused — a forged uuid finds nothing.
 *
 * Deliberately inside the admin domain rather than on a subdomain of its
 * own: a courier holds exactly one permission and therefore sees exactly one
 * nav item, which does not justify a second host, a scoped cookie and a
 * portal guard. Vendors earned a subdomain because they are outside the
 * company; couriers are staff.
 */
Route::middleware('permission:delivery.update')->group(function () {
    Route::get('deliveries', [CourierTaskController::class, 'index'])->name('admin.deliveries.index');
    // Before {shipment:uuid}, otherwise 'bulk-advance' is bound as a uuid.
    Route::post('deliveries/bulk-advance', [CourierTaskController::class, 'bulkAdvance'])->name('admin.deliveries.bulk-advance');
    Route::post('deliveries/{shipment:uuid}/advance', [CourierTaskController::class, 'advance'])->name('admin.deliveries.advance');
    Route::post('deliveries/{shipment:uuid}/pay-goods', [CourierTaskController::class, 'payGoods'])->name('admin.deliveries.pay-goods');
    Route::post('deliveries/{shipment:uuid}/deliver', [CourierTaskController::class, 'deliver'])->name('admin.deliveries.deliver');
    Route::post('deliveries/{shipment:uuid}/fail', [CourierTaskController::class, 'fail'])->name('admin.deliveries.fail');
    Route::post('deliveries/remit', [CourierTaskController::class, 'remit'])->name('admin.deliveries.remit');
});

/*
 * The dispatch desk. Assigning a parcel is an order-management act, not a
 * courier one — a courier must never be able to hand themselves work.
 */
Route::middleware('permission:orders.manage')->group(function () {
    Route::get('dispatch', [DispatchController::class, 'index'])->name('admin.dispatch.index');
    Route::post('dispatch/assign', [DispatchController::class, 'assign'])->name('admin.dispatch.assign');
    Route::post('dispatch/{shipment:uuid}/force-deliver', [DispatchController::class, 'forceDeliver'])->name('admin.dispatch.force-deliver');
    Route::post('dispatch/{shipment:uuid}/recall', [DispatchController::class, 'recall'])->name('admin.dispatch.recall');

    /*
     * Cash on delivery: who is holding what, and the settings that bound it.
     * Same permission as dispatch — it is the same job, and the ceiling is
     * not set without looking at what is currently out.
     */
    Route::get('cash', [CashController::class, 'index'])->name('admin.cash.index');
    Route::post('cash/settings', [CashController::class, 'updateSettings'])->name('admin.cash.settings');
    Route::post('cash/{courierCashMovement:uuid}/confirm', [CashController::class, 'confirmRemittance'])->name('admin.cash.confirm');
});

/*
 * Staff accounts. Its own permission because creating one is creating a way
 * into the admin domain — a heavier act than suspending a customer.
 */
Route::middleware('permission:staff.manage')->group(function () {
    Route::get('staff', [StaffController::class, 'index'])->name('admin.staff.index');
    Route::post('staff', [StaffController::class, 'store'])->name('admin.staff.store');
    Route::put('staff/{user:uuid}', [StaffController::class, 'update'])->name('admin.staff.update');
    Route::post('staff/{user:uuid}/suspend', [StaffController::class, 'suspend'])->name('admin.staff.suspend');
    Route::post('staff/{user:uuid}/reactivate', [StaffController::class, 'reactivate'])->name('admin.staff.reactivate');
    Route::post('staff/{user:uuid}/availability', [StaffController::class, 'toggleAvailability'])->name('admin.staff.availability');
    Route::post('staff/{user:uuid}/resend-code', [StaffController::class, 'resendPasswordCode'])
        ->middleware('throttle:10,1')->name('admin.staff.resend-code');
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

// ── Sprint 9: AI, reporting, and operational controls ──

Route::middleware('permission:customers.suspend')->group(function () {
    Route::get('customers', [UserManagementController::class, 'index'])->name('admin.users.index');
    Route::post('customers', [UserManagementController::class, 'store'])->name('admin.users.store');
    Route::post('customers/bulk', [UserManagementController::class, 'bulkUpdate'])->name('admin.users.bulk');
    Route::get('customers/{user:uuid}', [UserManagementController::class, 'show'])->name('admin.users.show');
    Route::post('customers/{user:uuid}/suspend', [UserManagementController::class, 'suspend'])->name('admin.users.suspend');
    Route::post('customers/{user:uuid}/ban', [UserManagementController::class, 'ban'])->name('admin.users.ban');
    Route::post('customers/{user:uuid}/reactivate', [UserManagementController::class, 'reactivate'])->name('admin.users.reactivate');
});

Route::middleware('permission:ai_settings.manage')->group(function () {
    Route::get('settings/ai', [AiSettingsController::class, 'edit'])->name('admin.settings.ai');
    Route::post('settings/ai', [AiSettingsController::class, 'update'])->name('admin.settings.ai.update');
});

Route::middleware('permission:reports.view')->group(function () {
    Route::get('reports', [ReportingController::class, 'index'])->name('admin.reports.index');
    Route::get('reports/export/{report}', [ReportingController::class, 'export'])->name('admin.reports.export');
});

/*
 * The wording of the terms, the privacy policy and the data-deletion
 * instructions. Behind settings.manage: this is the text customers are held
 * to and the text Google and Meta read during app review, so it belongs with
 * whoever sets policy rather than with whoever answers tickets.
 */
Route::middleware('permission:settings.manage')->group(function () {
    Route::get('settings/pages', [ContentPageController::class, 'index'])->name('admin.settings.pages');
    Route::post('settings/pages', [ContentPageController::class, 'store'])->name('admin.settings.pages.store');
    Route::put('settings/pages/{contentPage:uuid}', [ContentPageController::class, 'update'])->name('admin.settings.pages.update');
    Route::delete('settings/pages/{contentPage:uuid}', [ContentPageController::class, 'destroy'])->name('admin.settings.pages.destroy');
});
