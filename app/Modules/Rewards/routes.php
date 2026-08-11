<?php

use App\Modules\Rewards\Controllers\RewardsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
	Route::get('account/rewards', [RewardsController::class, 'index'])->name('rewards.index');
});
