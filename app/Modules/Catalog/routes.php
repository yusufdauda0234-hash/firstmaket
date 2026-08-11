<?php

use App\Modules\Catalog\Controllers\CatalogController;
use App\Modules\Catalog\Controllers\HomeController;
use App\Modules\Catalog\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

// Routes for the Catalog module are registered here and auto-loaded on the
// customer/vendor-facing domain by App\Providers\ModuleServiceProvider.
// Keep controllers thin; delegate to Actions/Services (see
// docs/FirstMaket_Developer_Guidelines.md).

// Public storefront — no authentication.
Route::get('/', HomeController::class)->name('home');
Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('catalog/suggest', [CatalogController::class, 'suggest'])
    ->middleware('throttle:60,1')->name('catalog.suggest');
Route::get('catalog/menu-products', [CatalogController::class, 'menuProducts'])
    ->middleware('throttle:60,1')->name('catalog.menu-products');
Route::get('compare', [CatalogController::class, 'compare'])->name('catalog.compare');
Route::get('product/{product:slug}', [CatalogController::class, 'show'])->name('catalog.product');

// Language / display currency / ship-to. A real request because translation is
// server-side: the next render must come back in the chosen language.
Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

// Vendor listing management moved to the Vendor Center subdomain — see
// app/Modules/Catalog/vendor-routes.php, required from routes/vendors.php.
