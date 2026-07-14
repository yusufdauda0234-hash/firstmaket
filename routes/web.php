<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Public/Home'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

// Every app/Modules/{Name}/routes.php is auto-loaded on this (customer/vendor)
// domain by App\Providers\ModuleServiceProvider, except Modules/Admin which
// is reserved for the isolated admin subdomain (see routes/admin.php).
