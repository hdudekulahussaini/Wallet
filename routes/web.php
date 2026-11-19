<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Wallet\BankAccountController;
use App\Http\Controllers\Wallet\WalletController;
use App\Http\Controllers\Wallet\WalletPaymentController;
use App\Http\Controllers\Wallet\WalletWithdrawalController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('wallet.index')
        : redirect()->route('login');
});

Auth::routes();

Route::get('/home', [HomeController::class, 'index'])->name('home');

/*
|--------------------------------------------------------------------------
| Authenticated Wallet Management Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->prefix('wallet')->name('wallet.')->group(function (): void {
    // Dashboard & Balance
    Route::get('/', [WalletController::class, 'index'])->name('index');
    Route::get('/balance', [WalletController::class, 'balance'])->name('balance');
    Route::get('/transactions', [WalletController::class, 'transactions'])->name('transactions');

    // Add Money (Cashfree Payment Gateway)
    Route::get('/add-money', [WalletPaymentController::class, 'showAddMoney'])->name('add-money');
    Route::post('/add-money/order', [WalletPaymentController::class, 'createOrder'])
        ->middleware('throttle:wallet-payments')
        ->name('add-money.order');
    Route::get('/add-money/return', [WalletPaymentController::class, 'handleReturn'])->name('add-money.return');
    Route::post('/add-money/verify/{orderId}', [WalletPaymentController::class, 'verifyOrder'])
        ->middleware('throttle:wallet-payments')
        ->name('add-money.verify');

    // Bank Accounts Management
    Route::get('/bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts');
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])
        ->middleware('throttle:wallet-bank-accounts')
        ->name('bank-accounts.store');
    Route::post('/bank-accounts/{bankAccount}/verify', [BankAccountController::class, 'verify'])
        ->middleware('throttle:wallet-bank-accounts')
        ->name('bank-accounts.verify');
    Route::delete('/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])
        ->name('bank-accounts.destroy');

    // Withdraw Money (Cashfree Payouts V2)
    Route::get('/withdraw', [WalletWithdrawalController::class, 'index'])->name('withdraw');
    Route::post('/withdraw', [WalletWithdrawalController::class, 'store'])
        ->middleware('throttle:wallet-withdrawals')
        ->name('withdraw.store');
    Route::post('/withdraw/{withdrawal}/sync', [WalletWithdrawalController::class, 'syncStatus'])
        ->middleware('throttle:wallet-withdrawals')
        ->name('withdraw.sync');
});
