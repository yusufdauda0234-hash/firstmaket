<?php

use App\Modules\Notifications\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// Routes for the Notifications module, auto-loaded on the customer-facing
// domain by App\Providers\ModuleServiceProvider: the in-app inbox and the
// per-category channel preferences.

Route::middleware('auth')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::put('notifications/preferences', [NotificationController::class, 'updatePreference'])->name('notifications.preferences.update');
});
