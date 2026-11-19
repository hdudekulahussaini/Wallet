<?php

use App\Http\Controllers\Wallet\WalletPaymentController;
use App\Http\Controllers\Wallet\WalletWithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cashfree Webhook Routes (Stateless; HMAC-SHA256 Signature Verified)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks/cashfree')
    ->name('webhooks.cashfree.')
    ->middleware('throttle:cashfree-webhooks')
    ->group(function (): void {
        Route::post('/payment', [WalletPaymentController::class, 'webhook'])
            ->middleware('cashfree.webhook:payment')
            ->name('payment');

        Route::post('/payout', [WalletWithdrawalController::class, 'webhook'])
            ->middleware('cashfree.webhook:payout')
            ->name('payout');
    });
