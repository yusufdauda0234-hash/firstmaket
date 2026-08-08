<?php

use App\Modules\Savings\Controllers\SavingsDashboardController;
use App\Modules\Savings\Controllers\SavingsGoalController;
use Illuminate\Support\Facades\Route;

// Routes for the Savings module are registered here and auto-loaded on the
// customer/vendor-facing domain by App\Providers\ModuleServiceProvider.
//
// There is no balance and no way to deposit: money is only ever paid into a
// specific Pay Small Small plan, and it only ever leaves as goods. No
// withdrawal route exists here or anywhere.

Route::middleware('auth')->group(function () {
    Route::get('savings', [SavingsDashboardController::class, 'show'])->name('savings.index');

    Route::get('savings/plans/{goal:uuid}', [SavingsGoalController::class, 'show'])->name('savings.goals.show');
    Route::get('savings/plans/{goal:uuid}/payments', [SavingsGoalController::class, 'payments'])->name('savings.goals.payments');
    Route::post('savings/plans/{goal:uuid}/pay', [SavingsGoalController::class, 'pay'])->name('savings.goals.pay');
    Route::post('savings/plans/{goal:uuid}/collect', [SavingsGoalController::class, 'fulfil'])->name('savings.goals.buy');
    // Changing the schedule and giving up are both money-affecting and both
    // reachable from one page, so they share a throttle.
    Route::get('savings/switch-options', [SavingsGoalController::class, 'switchOptions'])
        ->name('savings.switch-options');

    Route::post('savings/plans/{goal:uuid}/switch', [SavingsGoalController::class, 'switchItem'])
        ->middleware('throttle:10,1')
        ->name('savings.goals.switch');

    Route::post('savings/plans/{goal:uuid}/reschedule', [SavingsGoalController::class, 'reschedule'])
        ->middleware('throttle:10,1')
        ->name('savings.goals.reschedule');

    Route::post('savings/plans/{goal:uuid}/cancel', [SavingsGoalController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('savings.goals.cancel');
});
