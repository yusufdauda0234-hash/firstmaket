<?php

use App\Modules\Settings\Controllers\CountryListController;
use App\Modules\Settings\Controllers\LocalGovernmentController;
use App\Modules\Settings\Controllers\StateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Reserved under /api/v1 from Sprint 1 so the Phase 3E mobile apps have a
| stable, versioned surface to consume without a breaking migration later
| (docs/FirstMaket_Implementation_Plan.md section 1.3). No endpoints are
| defined yet — Phase 1 is Inertia-only.
|
*/

Route::prefix('v1')->group(function () {
    Route::get('countries/list/all', [CountryListController::class, 'index'])->name('api.countries.list');
    Route::get('countries/{country}/states', [StateController::class, 'byCountry'])->name('api.countries.states');
    Route::get('states/{state}/lgas', [LocalGovernmentController::class, 'byState'])->name('api.states.lgas');
});
