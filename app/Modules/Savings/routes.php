<?php

use App\Modules\Savings\Controllers\OpenSavingsController;
use App\Modules\Savings\Controllers\PayAtOnceController;
use App\Modules\Savings\Controllers\PlanController;
use App\Modules\Savings\Controllers\SavingsDashboardController;
use Illuminate\Support\Facades\Route;

// Routes for the Savings module are registered here and auto-loaded on the
// customer/vendor-facing domain by App\Providers\ModuleServiceProvider.
// Sprint 5 Purchase and Savings Engine: no withdrawal route exists here or
// anywhere — money only ever moves wallet → savings → product.

Route::middleware('auth')->group(function () {
    // Savings dashboard + Open Savings pot
    Route::get('savings', [SavingsDashboardController::class, 'show'])->name('savings.index');
    Route::post('savings/open/allocate', [OpenSavingsController::class, 'allocate'])
        ->name('savings.open.allocate');

    // Product Target Plans
    Route::get('product/{product:slug}/start-plan', [PlanController::class, 'start'])->name('savings.plans.start');
    Route::post('savings/plans', [PlanController::class, 'store'])->name('savings.plans.store');
    Route::get('savings/plans/{plan:uuid}', [PlanController::class, 'show'])->name('savings.plans.show');
    Route::post('savings/plans/{plan:uuid}/contribute', [PlanController::class, 'contribute'])
        ->name('savings.plans.contribute');
    Route::post('savings/plans/{plan:uuid}/pause', [PlanController::class, 'pause'])->name('savings.plans.pause');
    Route::post('savings/plans/{plan:uuid}/resume', [PlanController::class, 'resume'])->name('savings.plans.resume');
    Route::post('savings/plans/{plan:uuid}/redirect-open-savings', [PlanController::class, 'redirectOpenSavings'])
        ->name('savings.plans.redirect-open-savings');
    Route::post('savings/plans/{plan:uuid}/switch-product', [PlanController::class, 'switchProduct'])
        ->name('savings.plans.switch-product');

    // Pay At Once checkout
    Route::get('checkout/{product:slug}', [PayAtOnceController::class, 'create'])->name('checkout.pay-at-once');
    Route::post('checkout/{product:slug}', [PayAtOnceController::class, 'store'])->name('checkout.pay-at-once.store');
});
