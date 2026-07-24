<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\LiabilityController;
use App\Http\Controllers\Api\V1\MiscController;
use App\Http\Controllers\Api\V1\SyncPullController;
use App\Http\Controllers\Api\V1\SyncPushController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\SslTestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/auth/profile', [AuthController::class, 'profile']);
        Route::post('/auth/profile/avatar', [AuthController::class, 'uploadAvatar']);
        Route::delete('/auth/profile/avatar', [AuthController::class, 'deleteAvatar']);

        // Global reference data
        Route::get('/currencies', [MiscController::class, 'currencies']);
        Route::get('/currencies/{id}', [MiscController::class, 'showCurrency']);
        Route::get('/categories', [MiscController::class, 'categories']);
        Route::get('/categories/{id}', [MiscController::class, 'showCategory']);

        // The user's currency holdings
        Route::get('/user-currencies', [CurrencyController::class, 'index']);
        Route::post('/user-currencies', [CurrencyController::class, 'store']);
        Route::get('/user-currencies/{id}', [CurrencyController::class, 'show']);

        // Accounts (each resource includes a derived balance)
        Route::get('/accounts', [AccountController::class, 'index']);
        Route::post('/accounts', [AccountController::class, 'store']);
        Route::get('/accounts/{id}', [AccountController::class, 'show']);

        // Transactions + transfers
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{id}', [TransactionController::class, 'show']);
        Route::get('/transfers', [TransactionController::class, 'transfers']);
        Route::get('/transfers/{id}', [TransactionController::class, 'showTransfer']);

        // Liabilities + payments
        Route::get('/liabilities', [LiabilityController::class, 'index']);
        Route::get('/liabilities/{id}', [LiabilityController::class, 'show']);
        Route::get('/liability-payments', [LiabilityController::class, 'payments']);
        Route::get('/liability-payments/{id}', [LiabilityController::class, 'showPayment']);

        // Offline-first sync: batch read (pull) + batch write (push)
        Route::get('/sync/pull', [SyncPullController::class, 'index']);
        Route::post('/sync/push', [SyncPushController::class, 'store']);
    });
});

/*
| SSL pinning test harness (unversioned, non-prod only).
| Hard-gated behind config('ssltest.enabled') in the controller; see
| config/ssltest.php and ssl-test/README.md. Kept out of the /v1 group on
| purpose — this is an ops/QA surface, not part of the product API contract.
*/
Route::middleware(['auth:sanctum', 'throttle:6,1'])->prefix('config')->group(function () {
    Route::get('ssl-info', [SslTestController::class, 'info']);
    Route::post('ssl-rotation', [SslTestController::class, 'rotate']);
    Route::post('ssl-change/{v}', [SslTestController::class, 'change'])->whereIn('v', ['a', 'b', 'c']);
});
