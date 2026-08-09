<?php

use App\Modules\Support\Controllers\ContentPageController;
use App\Modules\Support\Controllers\FaqController;
use App\Modules\Support\Controllers\SupportCenterController;
use Illuminate\Support\Facades\Route;

// Routes for the Support module, auto-loaded on the customer-facing domain
// by App\Providers\ModuleServiceProvider. Agent-side routes live on the
// admin subdomain (app/Modules/Admin/routes.php, permission:support.manage).

// Public FAQ page (also linked from the storefront footer).
Route::get('faq', FaqController::class)->name('faq');

/*
 * Admin-editable public pages.
 *
 * The first three URLs are fixed and must not be renamed: they are typed
 * into the Google OAuth consent screen and Meta's app review, both of which
 * fetch them and fail the integration on a 404. /legal/{slug} serves
 * everything else and redirects these three to the URLs above, so each page
 * has exactly one address.
 */
Route::get('terms', [ContentPageController::class, 'terms'])->name('legal.terms');
Route::get('privacy-policy', [ContentPageController::class, 'privacy'])->name('legal.privacy');
Route::get('data-deletion', [ContentPageController::class, 'dataDeletion'])->name('legal.data-deletion');
Route::get('privacy', [ContentPageController::class, 'privacyAlias'])->name('legal.privacy-alias');
Route::get('legal', [ContentPageController::class, 'index'])->name('legal.index');
Route::get('legal/{slug}', [ContentPageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')->name('legal.show');

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
