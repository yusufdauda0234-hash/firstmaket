<?php

use App\Modules\Savings\Controllers\GroupSavingsController;
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

    // Pausing only suspends reminders and automatic debit — the plan, its
    // frozen price and everything paid into it are untouched.
    Route::post('savings/plans/{goal:uuid}/pause', [SavingsGoalController::class, 'pause'])
        ->middleware('throttle:10,1')
        ->name('savings.goals.pause');

    Route::post('savings/plans/{goal:uuid}/resume', [SavingsGoalController::class, 'resume'])
        ->middleware('throttle:10,1')
        ->name('savings.goals.resume');

    // Automatic instalments off the card already saved from a manual payment.
    Route::post('savings/plans/{goal:uuid}/automatic-debit', [SavingsGoalController::class, 'enableAutomaticDebit'])
        ->middleware('throttle:10,1')
        ->name('savings.goals.automatic-debit.enable');

    Route::delete('savings/plans/{goal:uuid}/automatic-debit', [SavingsGoalController::class, 'disableAutomaticDebit'])
        ->middleware('throttle:10,1')
        ->name('savings.goals.automatic-debit.disable');
});

/*
 * Saving with other people (Phase 3B).
 *
 * None of these routes move money on their own. A group purchase and a
 * cooperative turn both pay through the ordinary verified plan-payment path;
 * a family group is read-only by construction. There is still no withdrawal
 * anywhere, and no route here creates one.
 *
 * Invitations are throttled because they take an email or phone number and
 * report whether it could be invited — a slow trickle is fine, a script
 * enumerating the user base is not.
 */
Route::middleware('auth')->prefix('savings/together')->name('savings.together.')->group(function () {
    Route::get('/', [GroupSavingsController::class, 'index'])->name('index');

    // Group purchase
    Route::post('groups', [GroupSavingsController::class, 'storeGroup'])->name('groups.store');
    Route::post('groups/join', [GroupSavingsController::class, 'joinGroup'])->middleware('throttle:10,1')->name('groups.join');
    Route::post('groups/{group:uuid}/invite', [GroupSavingsController::class, 'inviteToGroup'])->middleware('throttle:20,1')->name('groups.invite');
    Route::post('groups/{group:uuid}/accept', [GroupSavingsController::class, 'acceptGroup'])->name('groups.accept');
    Route::post('groups/{group:uuid}/exit', [GroupSavingsController::class, 'exitGroup'])->name('groups.exit');
    Route::post('groups/{group:uuid}/cancel', [GroupSavingsController::class, 'cancelGroup'])->name('groups.cancel');

    // Family
    Route::post('family', [GroupSavingsController::class, 'storeFamily'])->name('family.store');
    Route::post('family/join', [GroupSavingsController::class, 'joinFamily'])->middleware('throttle:10,1')->name('family.join');
    Route::post('family/{family:uuid}/invite', [GroupSavingsController::class, 'inviteToFamily'])->middleware('throttle:20,1')->name('family.invite');
    Route::post('family/{family:uuid}/sharing', [GroupSavingsController::class, 'setFamilySharing'])->name('family.sharing');
    Route::post('family/{family:uuid}/leave', [GroupSavingsController::class, 'leaveFamily'])->name('family.leave');

    // Cooperative
    Route::post('cooperatives', [GroupSavingsController::class, 'storeCooperative'])->name('cooperatives.store');
    Route::post('cooperatives/join', [GroupSavingsController::class, 'joinCooperative'])->middleware('throttle:10,1')->name('cooperatives.join');
    Route::post('cooperatives/{group:uuid}/invite', [GroupSavingsController::class, 'inviteToCooperative'])->middleware('throttle:20,1')->name('cooperatives.invite');
    Route::post('cooperatives/{group:uuid}/start', [GroupSavingsController::class, 'startCooperative'])->name('cooperatives.start');
    Route::post('cooperatives/cycles/{cycle}/nominate', [GroupSavingsController::class, 'nominatePlan'])->name('cooperatives.cycles.nominate');
    Route::post('cooperatives/cycles/{cycle}/close', [GroupSavingsController::class, 'closeCycle'])->name('cooperatives.cycles.close');
});
