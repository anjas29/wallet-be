<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Example;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;

#[Group('Sync', weight: 10)]
class SyncPushController extends Controller
{
    /**
     * Example `changes` payload covering several entities and operations.
     */
    private const CHANGES_EXAMPLE = [
        [
            'client_change_id' => 'c1',
            'entity' => 'transaction',
            'op' => 'create',
            'id' => '01K3TX0000000000000000TX01',
            'data' => [
                'account_id' => '01K3AC0000000000000000AC01',
                'category_id' => '01K3CT0000000000000000CT01',
                'type' => 'expense',
                'amount' => '12.50',
                'exchange_rate_to_anchor' => '1',
                'description' => 'Lunch',
                'transaction_date' => '2026-07-10',
            ],
        ],
        [
            'client_change_id' => 'c2',
            'entity' => 'transfer',
            'op' => 'create',
            'id' => '01K3TR0000000000000000TR01',
            'data' => [
                'from_account_id' => '01K3AC0000000000000000AC01',
                'to_account_id' => '01K3AC0000000000000000AC02',
                'from_amount' => '100.00',
                'to_amount' => '100.00',
                'fee' => '0',
                'transfer_date' => '2026-07-10',
            ],
        ],
        [
            'client_change_id' => 'c3',
            'entity' => 'account',
            'op' => 'create',
            'id' => '01K3AC0000000000000000AC03',
            'data' => [
                'user_currency_id' => '01K3UC0000000000000000UC01',
                'name' => 'Savings',
                'notes' => 'Emergency fund',
                'type' => 'savings',
                'color' => '#22C55E',
                'initial_balance' => '0',
                'is_default' => false,
            ],
        ],
        [
            'client_change_id' => 'c4',
            'entity' => 'transaction',
            'op' => 'delete',
            'id' => '01K3TX0000000000000000TX99',
        ],
    ];

    /**
     * Example response envelope: one applied item (with the resulting record) and one failed item.
     */
    private const RESPONSE_EXAMPLE = [
        'success' => true,
        'status_code' => 200,
        'message' => '',
        'data' => [
            'results' => [
                [
                    'client_change_id' => 'c1',
                    'id' => '01K3TX0000000000000000TX01',
                    'entity' => 'transaction',
                    'status' => 'applied',
                    'record' => [
                        'id' => '01K3TX0000000000000000TX01',
                        'account_id' => '01K3AC0000000000000000AC01',
                        'category_id' => '01K3CT0000000000000000CT01',
                        'type' => 'expense',
                        'amount' => '12.50',
                        'transaction_date' => '2026-07-10',
                        'created_at' => '2026-07-10T03:00:00+00:00',
                        'updated_at' => '2026-07-10T03:00:00+00:00',
                    ],
                ],
                [
                    'client_change_id' => 'c5',
                    'id' => '01K3TX00000000000000BADID',
                    'entity' => 'transaction',
                    'status' => 'failed',
                    'error' => [
                        'message' => 'Validation failed.',
                        'errors' => [
                            'data' => ['The selected account or category is invalid.'],
                        ],
                    ],
                ],
            ],
            'server_time' => '2026-07-10T03:00:00.000000Z',
        ],
    ];

    public function __construct(private SyncService $sync) {}

    /**
     * Push changes
     *
     * Offline-first batch write endpoint. Send a list of `changes`; each is applied **independently**
     * and reported on its own. This is the only write path for wallet data — the GET endpoints are
     * read-only.
     *
     * 📘 **Copy/paste guide** — a ready payload for every entity and operation:
     * [/docs/push-changes](/docs/push-changes)
     *
     * Each change is an object:
     *
     * ```json
     * { "client_change_id": "c1", "entity": "transaction", "op": "create", "id": "<client ULID>", "data": { ... } }
     * ```
     *
     * - `id` is a **client-generated ULID**; `create`/`update` upsert by it, so retries are safe.
     * - `delete` is a soft delete and is **idempotent** (a missing/already-deleted row is a no-op).
     * - `client_change_id` is echoed back untouched so the client can reconcile each result.
     *
     * ### Supported entities × operations
     *
     * | entity | create | update | delete |
     * |---|---|---|---|
     * | `transaction` | ✓ | ✓ | ✓ |
     * | `transfer` | ✓ | ✓ | ✓ |
     * | `account` | ✓ | ✓ | ✓ |
     * | `user_currency` | ✓ | ✓ | — (delete not supported) |
     * | `user_category` | ✓ | ✓ | ✓ |
     * | `liability` | ✓ | ✓ | ✓ |
     * | `liability_payment` | ✓ | ✓ | ✓ (owned via its parent liability) |
     *
     * `currency` and `category` are global reference data and are **not** writable here — user-owned
     * categories are written via `user_category` (the mobile client seeds these from the global list).
     *
     * ### `data` fields per entity (create/update)
     *
     * - **transaction**: `account_id`, `category_id`, `type` (`income`|`expense`), `amount`,
     *   `exchange_rate_to_anchor`, `description?`, `transaction_date`. A transaction is always in its
     *   account's currency (no `currency_id`).
     * - **transfer**: `from_account_id`, `to_account_id`, `from_amount`, `to_amount`, `exchange_rate?`,
     *   `fee`, `description?`, `transfer_date`.
     * - **account**: `user_currency_id`, `name`, `notes?`, `type` (`bank_account`|`cash`|`credit_card`|`savings`), `color?`,
     *   `initial_balance`, `is_default`.
     * - **user_currency**: `currency_id`, `exchange_rate`, `is_anchor`.
     * - **user_category**: `name`, `type` (`income`|`expense`), `icon`, `color?`.
     * - **liability**: `user_currency_id`, `name`, `type` (`loan`|`credit_card`|`personal`),
     *   `principal_amount`, `interest_rate?`, `due_date?`, `notes?`, `is_settled`.
     * - **liability_payment**: `liability_id`, `account_id`, `amount`, `payment_date`, `note?`.
     *
     * ### Result semantics (partial success)
     *
     * The request returns **200** even if some items fail. Each result carries a `status`:
     * - `applied` → includes the resulting `record` (create/update) — delete omits it.
     * - `failed` → includes an `error` (`message`, plus per-field `errors` for validation failures),
     *   e.g. an invalid foreign key or a record the caller doesn't own. Other items still apply.
     *
     * A **401** envelope is returned if unauthenticated; a **422** if `changes` is missing or not an array.
     */
    #[BodyParameter('changes', description: 'Array of change objects to apply, in order.', required: true, type: 'object[]', example: self::CHANGES_EXAMPLE)]
    #[Response(status: 200, description: 'Per-item results (partial success).', examples: [new Example(value: self::RESPONSE_EXAMPLE, summary: 'Mixed applied/failed batch')])]
    public function store(Request $request)
    {
        $data = $request->validate([
            'changes' => ['required', 'array'],
        ]);

        $results = $this->sync->apply($request->user(), $data['changes']);

        return $this->success([
            'results' => $results,
            'server_time' => now()->toISOString(),
        ]);
    }
}
