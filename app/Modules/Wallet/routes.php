<?php

use App\Modules\Wallet\Controllers\ReceiptController;
use App\Modules\Wallet\Controllers\TransactionController;
use App\Modules\Wallet\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

// Routes for the Wallet module are auto-loaded on the customer/vendor-facing
// domain by App\Providers\ModuleServiceProvider. Deposit-only: there is no
// withdrawal route here or anywhere.

Route::middleware('auth')->group(function () {
    Route::get('wallet', [WalletController::class, 'show'])->name('wallet.index');
    Route::get('wallet/transactions', [TransactionController::class, 'index'])->name('wallet.transactions');
    Route::get('wallet/receipts/{transaction:uuid}', [ReceiptController::class, 'show'])->name('wallet.receipt');
});
