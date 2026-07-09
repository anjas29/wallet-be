<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SyncPushController;
use App\Http\Controllers\Api\V1\WalletReadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/auth/profile', [AuthController::class, 'profile']);

        Route::get('/currencies', [WalletReadController::class, 'currencies']);
        Route::get('/currencies/{id}', [WalletReadController::class, 'showCurrency']);
        Route::get('/categories', [WalletReadController::class, 'categories']);
        Route::get('/categories/{id}', [WalletReadController::class, 'showCategory']);
        Route::get('/user-currencies', [WalletReadController::class, 'userCurrencies']);
        Route::get('/user-currencies/{id}', [WalletReadController::class, 'showUserCurrency']);
        Route::get('/accounts', [WalletReadController::class, 'accounts']);
        Route::get('/accounts/{id}', [WalletReadController::class, 'showAccount']);
        Route::get('/transactions', [WalletReadController::class, 'transactions']);
        Route::get('/transactions/{id}', [WalletReadController::class, 'showTransaction']);
        Route::get('/transfers', [WalletReadController::class, 'transfers']);
        Route::get('/transfers/{id}', [WalletReadController::class, 'showTransfer']);
        Route::get('/liabilities', [WalletReadController::class, 'liabilities']);
        Route::get('/liabilities/{id}', [WalletReadController::class, 'showLiability']);
        Route::get('/liability-payments', [WalletReadController::class, 'liabilityPayments']);
        Route::get('/liability-payments/{id}', [WalletReadController::class, 'showLiabilityPayment']);

        Route::post('/sync/push', [SyncPushController::class, 'store']);
    });
});
