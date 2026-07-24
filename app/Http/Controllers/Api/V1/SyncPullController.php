<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CurrencyResource;
use App\Http\Resources\LiabilityPaymentResource;
use App\Http\Resources\LiabilityResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransferResource;
use App\Http\Resources\UserCategoryResource;
use App\Http\Resources\UserCurrencyResource;
use App\Services\AccountService;
use App\Services\CategoryService;
use App\Services\CurrencyService;
use App\Services\LiabilityPaymentService;
use App\Services\LiabilityService;
use App\Services\TransactionService;
use App\Services\TransferService;
use App\Services\UserCategoryService;
use App\Services\UserCurrencyService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;

#[Group('Sync', weight: 10)]
class SyncPullController extends Controller
{
    public function __construct(
        private CurrencyService $currencies,
        private CategoryService $categories,
        private UserCurrencyService $userCurrencies,
        private UserCategoryService $userCategories,
        private AccountService $accounts,
        private TransactionService $transactions,
        private TransferService $transfers,
        private LiabilityService $liabilities,
        private LiabilityPaymentService $liabilityPayments,
    ) {}

    /**
     * Pull changes
     *
     * Offline-first batch read — the counterpart to `POST /sync/push`. Returns every entity the
     * client tracks in a single call.
     *
     * - **Initial sync:** omit `since` to get all current (non-deleted) records.
     * - **Delta sync:** pass `since` (the `server_time` from your previous pull) to get only records
     *   changed after it — **including soft-deleted rows (tombstones)** so deletions propagate.
     *   Detect a deletion by a non-null `deleted_at` on the returned record.
     *
     * Store the returned `server_time` and send it as `since` on the next pull. Global reference data
     * (`currencies`, `categories`) is included alongside the user's own records (`user_categories` and
     * the rest are user-owned). Each collection is
     * capped by `limit` (max 500); if you hit the cap, pull again with the last record's `updated_at`.
     */
    #[QueryParameter('since', description: 'ISO-8601 cursor; return only records changed after it (incl. tombstones).', required: false, type: 'string', example: '2026-07-10T03:00:00.000000Z')]
    #[QueryParameter('limit', description: 'Max records per collection (capped at 500).', required: false, type: 'integer', example: 500)]
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $since = $request->input('since');
        $limit = $this->limit($request);

        return $this->success([
            'currencies' => CurrencyResource::collection($this->currencies->list($since, $limit)),
            'categories' => CategoryResource::collection($this->categories->list(null, $since, $limit)),
            'user_currencies' => UserCurrencyResource::collection($this->userCurrencies->list($userId, $since, $limit)),
            'user_categories' => UserCategoryResource::collection($this->userCategories->list($userId, null, $since, $limit)),
            'accounts' => AccountResource::collection($this->accounts->list($userId, $since, $limit)),
            'transactions' => TransactionResource::collection($this->transactions->list($userId, $since, $limit)),
            'transfers' => TransferResource::collection($this->transfers->list($userId, $since, $limit)),
            'liabilities' => LiabilityResource::collection($this->liabilities->list($userId, $since, $limit)),
            'liability_payments' => LiabilityPaymentResource::collection($this->liabilityPayments->list($userId, $since, $limit)),
            'server_time' => now()->toISOString(),
        ]);
    }
}
