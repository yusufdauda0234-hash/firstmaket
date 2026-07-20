<?php

use App\Modules\Support\Controllers\FaqController;
use App\Modules\Support\Controllers\SupportCenterController;
use Illuminate\Support\Facades\Route;

// Routes for the Support module, auto-loaded on the customer-facing domain
// by App\Providers\ModuleServiceProvider. Agent-side routes live on the
// admin subdomain (app/Modules/Admin/routes.php, permission:support.manage).

// Public FAQ page (also linked from the storefront footer).
Route::get('faq', FaqController::class)->name('faq');

Route::middleware('auth')->group(function () {
    Route::get('support', [SupportCenterController::class, 'index'])->name('support.index');
    Route::post('support/tickets', [SupportCenterController::class, 'storeTicket'])
        ->middleware('throttle:10,60')->name('support.tickets.store');
    Route::get('support/tickets/{ticket:uuid}', [SupportCenterController::class, 'showTicket'])->name('support.tickets.show');
    Route::post('support/tickets/{ticket:uuid}/reply', [SupportCenterController::class, 'reply'])
        ->middleware('throttle:30,60')->name('support.tickets.reply');
    Route::post('support/hotline', [SupportCenterController::class, 'requestHotline'])
        ->middleware('throttle:5,60')->name('support.hotline.request');
});
