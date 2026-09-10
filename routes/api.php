<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AiChatController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\LiabilityController;
use App\Http\Controllers\Api\V1\MiscController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\RecurringTransactionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SyncPullController;
use App\Http\Controllers\Api\V1\SyncPushController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\SslTestController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController as StripeWebhookController;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Global reference data — public (no auth). Non-sensitive lists the app needs
    // before login (e.g. to seed pickers / user categories from the templates).
    Route::get('/currencies', [MiscController::class, 'currencies']);
    Route::get('/currencies/{id}', [MiscController::class, 'showCurrency']);
    Route::get('/categories', [MiscController::class, 'categories']);
    Route::get('/categories/{id}', [MiscController::class, 'showCategory']);

    // Subscription plans — public, so a pricing/paywall screen can list them pre-login.
    Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/auth/profile', [AuthController::class, 'profile']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('/auth/profile/avatar', [AuthController::class, 'uploadAvatar']);
        Route::delete('/auth/profile/avatar', [AuthController::class, 'deleteAvatar']);

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

        // Reports
        Route::get('/reports/transactions', [ReportController::class, 'transactions']);

        // Liabilities + payments
        Route::get('/liabilities', [LiabilityController::class, 'index']);
        Route::get('/liabilities/{id}', [LiabilityController::class, 'show']);
        Route::get('/liability-payments', [LiabilityController::class, 'payments']);
        Route::get('/liability-payments/{id}', [LiabilityController::class, 'showPayment']);

        // Budgets (each resource includes a derived spent/remaining for its resolved period)
        Route::get('/budgets', [BudgetController::class, 'index']);
        Route::get('/budgets/{id}', [BudgetController::class, 'show']);

        // Recurring transaction templates (actual Transaction rows are generated server-side)
        Route::get('/recurring-transactions', [RecurringTransactionController::class, 'index']);
        Route::get('/recurring-transactions/{id}', [RecurringTransactionController::class, 'show']);

        // Subscriptions. POST / returns a Stripe Checkout URL (hosted payment page) rather
        // than an activated subscription — see SubscriptionController::store().
        Route::get('/subscriptions/me', [SubscriptionController::class, 'show']);
        Route::post('/subscriptions', [SubscriptionController::class, 'store']);
        Route::post('/subscriptions/swap', [SubscriptionController::class, 'swap']);
        Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancel']);
        Route::post('/subscriptions/resume', [SubscriptionController::class, 'resume']);

        // Offline-first sync: batch read (pull) + batch write (push)
        Route::get('/sync/pull', [SyncPullController::class, 'index']);
        Route::post('/sync/push', [SyncPushController::class, 'store']);

        // Receipt scanning (Gemini Developer API)
        Route::post('/receipts/scan', [ReceiptController::class, 'scan']);

        // AI analyst: read-only financial chat. POST /ai/chat streams Server-Sent Events
        // rather than the usual JSON envelope, and is throttled because each turn costs an
        // upstream call and holds the connection open for its duration.
        //
        // 5/min, not 20: one turn is up to GEMINI_MAX_TOOL_ITERATIONS upstream requests, so even
        // this can outrun a free-tier per-minute quota if a user chats flat out. It bounds the
        // burst; the per-day ration in AiChatService is what bounds the total.
        Route::prefix('ai')->group(function () {
            Route::post('/chat', [AiChatController::class, 'chat'])->middleware('throttle:5,1');
            Route::get('/conversations', [AiChatController::class, 'index']);
            Route::get('/conversations/{id}', [AiChatController::class, 'show']);
            Route::delete('/conversations/{id}', [AiChatController::class, 'destroy']);
        });
    });
});

/*
| Stripe webhook (unauthenticated, Stripe-signature verified by Cashier's own
| VerifyWebhookSignature middleware — see config('cashier.webhook.secret')). Kept out of
| the /v1 group on purpose: it's Stripe calling us, not a mobile-app endpoint.
*/
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handleWebhook']);

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
