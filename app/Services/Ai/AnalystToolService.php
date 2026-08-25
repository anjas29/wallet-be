<?php

namespace App\Services\Ai;

use App\Models\Account;
use App\Models\Liability;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\UserCategory;
use App\Models\UserCurrency;
use App\Services\AccountService;
use App\Services\BudgetService;
use App\Services\LiabilityService;
use Illuminate\Support\Carbon;

/**
 * Read-only aggregates exposed to Gemini as callable functions.
 *
 * SCOPING IS THIS CLASS'S JOB. The app has no global scopes, no policies and no model-layer
 * tenancy trait — every query below must filter on $userId itself. A missed filter here leaks
 * one user's finances into another's chat, so treat that as the invariant under test.
 *
 * Money: every figure returned is converted to the user's anchor currency and labelled with
 * `currency`, so the model never sums unlike currencies. Transactions use the
 * `exchange_rate_to_anchor` snapshotted at write time (historical reports stay stable);
 * balances and liabilities use the account/liability currency's current rate, matching
 * ReportService::buildSummary().
 *
 * Row-level tools (list_transactions, list_transfers, get_liabilities) return each record's `id`
 * so the model can cite it in a `[[transaction:<id>]]` reference tag and the app can open the
 * record. Aggregates deliberately do not — there is no single record to open. See
 * App\Services\Ai\AnswerTagFilter, which drops any tag whose id was not real.
 */
class AnalystToolService
{
    private const MAX_TRANSACTIONS = 50;

    public function __construct(
        private AccountService $accounts,
        private BudgetService $budgets,
        private LiabilityService $liabilities,
    ) {}

    /**
     * Gemini `functionDeclarations`. Built per-user so id arguments can be pinned to an enum of
     * the caller's own records — the same trick ReceiptScanService::buildSchema() uses to stop
     * the model inventing category ids.
     *
     * @return list<array<string, mixed>>
     */
    public function declarations(string $userId): array
    {
        $categoryIds = UserCategory::where('user_id', $userId)->pluck('id')->all();
        $accountIds = Account::where('user_id', $userId)->pluck('id')->all();

        $dateRange = [
            'start_date' => ['type' => 'STRING', 'description' => 'Inclusive start, yyyy-MM-dd.'],
            'end_date' => ['type' => 'STRING', 'description' => 'Inclusive end, yyyy-MM-dd.'],
        ];

        return [
            [
                'name' => 'get_account_balances',
                'description' => 'Current balance of every account the user owns, plus their total '
                    .'net worth. Use for "how much do I have" and net-worth questions.',
                'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
            ],
            [
                'name' => 'get_spending_by_category',
                'description' => 'Total spend (or income) per category over a date range, largest '
                    .'first. Use for "where did my money go" and overspending questions.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $dateRange + [
                        'type' => ['type' => 'STRING', 'enum' => ['income', 'expense']],
                    ],
                    'required' => ['start_date', 'end_date', 'type'],
                ],
            ],
            [
                'name' => 'get_income_expense_summary',
                'description' => 'Income, expense and net totals bucketed by month or day over a '
                    .'date range. Use for trends and month-over-month comparisons.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $dateRange + [
                        'group_by' => ['type' => 'STRING', 'enum' => ['month', 'day']],
                    ],
                    'required' => ['start_date', 'end_date', 'group_by'],
                ],
            ],
            [
                'name' => 'list_transactions',
                'description' => 'Individual transactions in a date range, largest amount first, '
                    .'capped at '.self::MAX_TRANSACTIONS.'. Use when the user asks about specific '
                    .'purchases rather than totals. Each row carries an `id` for a '
                    .'[[transaction:<id>]] reference tag.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $dateRange + [
                        'type' => ['type' => 'STRING', 'enum' => ['income', 'expense']],
                        'category_id' => $categoryIds === []
                            ? ['type' => 'STRING']
                            : ['type' => 'STRING', 'enum' => $categoryIds],
                        'account_id' => $accountIds === []
                            ? ['type' => 'STRING']
                            : ['type' => 'STRING', 'enum' => $accountIds],
                        'limit' => ['type' => 'INTEGER', 'description' => 'Max rows, capped at '.self::MAX_TRANSACTIONS.'.'],
                    ],
                    'required' => ['start_date', 'end_date'],
                ],
            ],
            [
                'name' => 'list_transfers',
                'description' => 'Money the user moved between their own accounts in a date range, '
                    .'newest first, capped at '.self::MAX_TRANSACTIONS.'. Transfers are neither '
                    .'income nor expense and never appear in spending totals or net worth, so use '
                    .'this to explain a balance that dropped without any spending. Each row carries '
                    .'an `id` for a [[transfer:<id>]] reference tag.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $dateRange + [
                        'limit' => ['type' => 'INTEGER', 'description' => 'Max rows, capped at '.self::MAX_TRANSACTIONS.'.'],
                    ],
                    'required' => ['start_date', 'end_date'],
                ],
            ],
            [
                'name' => 'get_budget_status',
                'description' => 'Every budget with its cap, amount spent so far and remaining, for '
                    .'its current period. Use for "am I on track" questions.',
                'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
            ],
            [
                'name' => 'get_liabilities',
                'description' => 'Debts and loans with principal, amount repaid and outstanding '
                    .'balance. Use for "how much do I owe" questions. Each row carries an `id` for '
                    .'a [[liability:<id>]] reference tag.',
                'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
            ],
        ];
    }

    /**
     * Dispatch a model-requested call. A `match` whitelist, never dynamic dispatch: $name is
     * model output. Unknown names come back as a tool error the model can recover from rather
     * than an exception that kills the stream.
     */
    public function call(string $userId, string $name, array $args): array
    {
        return match ($name) {
            'get_account_balances' => $this->getAccountBalances($userId),
            'get_spending_by_category' => $this->getSpendingByCategory($userId, $args),
            'get_income_expense_summary' => $this->getIncomeExpenseSummary($userId, $args),
            'list_transactions' => $this->listTransactions($userId, $args),
            'list_transfers' => $this->listTransfers($userId, $args),
            'get_budget_status' => $this->getBudgetStatus($userId),
            'get_liabilities' => $this->getLiabilities($userId),
            default => ['error' => "Unknown tool '{$name}'."],
        };
    }

    public function getAccountBalances(string $userId): array
    {
        $accounts = Account::where('user_id', $userId)->with('userCurrency.currency')->get();

        if ($accounts->isEmpty()) {
            return ['currency' => $this->anchorCode($userId), 'accounts' => [], 'net_worth' => '0.00'];
        }

        $this->accounts->attachBalances($userId, $accounts);

        $netWorth = 0.0;
        $rows = [];

        foreach ($accounts as $account) {
            $rate = (float) ($account->userCurrency?->exchange_rate ?? 1);
            $inAnchor = (float) $account->balance * $rate;
            $netWorth += $inAnchor;

            $rows[] = [
                'name' => $account->name,
                'type' => $account->type,
                'balance' => $this->money($inAnchor),
                'native_balance' => $account->balance,
                'native_currency' => $account->userCurrency?->currency?->code,
            ];
        }

        return [
            'currency' => $this->anchorCode($userId),
            'accounts' => $rows,
            'net_worth' => $this->money($netWorth),
        ];
    }

    public function getSpendingByCategory(string $userId, array $args): array
    {
        [$start, $end] = $this->dateRange($args);
        $type = in_array($args['type'] ?? null, ['income', 'expense'], true) ? $args['type'] : 'expense';

        $totals = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', $type)
            ->whereBetween('transaction_date', [$start, $end])
            ->join('user_categories', 'user_categories.id', '=', 'transactions.category_id')
            ->groupBy('user_categories.name')
            ->selectRaw('user_categories.name as category, SUM(amount * exchange_rate_to_anchor) as total, COUNT(*) as count')
            ->orderByDesc('total')
            ->get();

        return [
            'currency' => $this->anchorCode($userId),
            'start_date' => $start,
            'end_date' => $end,
            'type' => $type,
            'total' => $this->money((float) $totals->sum('total')),
            'categories' => $totals->map(fn ($row) => [
                'category' => $row->category,
                'amount' => $this->money((float) $row->total),
                'transaction_count' => (int) $row->count,
            ])->all(),
        ];
    }

    public function getIncomeExpenseSummary(string $userId, array $args): array
    {
        [$start, $end] = $this->dateRange($args);
        $groupBy = ($args['group_by'] ?? 'month') === 'day' ? 'day' : 'month';
        $format = $groupBy === 'day' ? 'YYYY-MM-DD' : 'YYYY-MM';

        $rows = Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$start, $end])
            ->groupByRaw("to_char(transaction_date, '{$format}'), type")
            ->selectRaw("to_char(transaction_date, '{$format}') as bucket, type, SUM(amount * exchange_rate_to_anchor) as total")
            ->orderBy('bucket')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[$row->bucket] ??= ['period' => $row->bucket, 'income' => 0.0, 'expense' => 0.0];
            $buckets[$row->bucket][$row->type] = (float) $row->total;
        }

        return [
            'currency' => $this->anchorCode($userId),
            'group_by' => $groupBy,
            'periods' => array_values(array_map(fn (array $b) => [
                'period' => $b['period'],
                'income' => $this->money($b['income']),
                'expense' => $this->money($b['expense']),
                'net' => $this->money($b['income'] - $b['expense']),
            ], $buckets)),
        ];
    }

    public function listTransactions(string $userId, array $args): array
    {
        [$start, $end] = $this->dateRange($args);
        $limit = min((int) ($args['limit'] ?? self::MAX_TRANSACTIONS), self::MAX_TRANSACTIONS);

        $rows = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transaction_date', [$start, $end])
            ->when(
                in_array($args['type'] ?? null, ['income', 'expense'], true),
                fn ($query) => $query->where('transactions.type', $args['type'])
            )
            // Ownership of the filter ids is already guaranteed by the user_id predicate above:
            // a category or account belonging to someone else matches no row of this user's.
            ->when(! empty($args['category_id']), fn ($query) => $query->where('category_id', $args['category_id']))
            ->when(! empty($args['account_id']), fn ($query) => $query->where('account_id', $args['account_id']))
            ->leftJoin('user_categories', 'user_categories.id', '=', 'transactions.category_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->orderByDesc('transactions.amount')
            ->limit($limit)
            ->get([
                'transactions.id',
                'transactions.transaction_date',
                'transactions.description',
                'transactions.type',
                'transactions.amount',
                'transactions.exchange_rate_to_anchor',
                'user_categories.name as category',
                'accounts.name as account',
            ]);

        return [
            'currency' => $this->anchorCode($userId),
            'truncated_at' => $rows->count() === $limit ? $limit : null,
            'transactions' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'date' => Carbon::parse($row->transaction_date)->toDateString(),
                'description' => $row->description,
                'category' => $row->category,
                'account' => $row->account,
                'type' => $row->type,
                'amount' => $this->money((float) $row->amount * (float) $row->exchange_rate_to_anchor),
            ])->all(),
        ];
    }

    /**
     * Transfers sit outside the income/expense model, so no other tool surfaces them. Amounts are
     * converted with the *source* account's current rate — a transfer's own `exchange_rate` maps
     * from_amount to to_amount, not either side to the anchor.
     */
    public function listTransfers(string $userId, array $args): array
    {
        [$start, $end] = $this->dateRange($args);
        $limit = min((int) ($args['limit'] ?? self::MAX_TRANSACTIONS), self::MAX_TRANSACTIONS);

        $rows = Transfer::query()
            ->where('transfers.user_id', $userId)
            ->whereBetween('transfer_date', [$start, $end])
            ->leftJoin('accounts as from_accounts', 'from_accounts.id', '=', 'transfers.from_account_id')
            ->leftJoin('accounts as to_accounts', 'to_accounts.id', '=', 'transfers.to_account_id')
            ->leftJoin('user_currencies as from_currencies', 'from_currencies.id', '=', 'from_accounts.user_currency_id')
            ->orderByDesc('transfers.transfer_date')
            ->orderByDesc('transfers.id')
            ->limit($limit)
            ->get([
                'transfers.id',
                'transfers.transfer_date',
                'transfers.description',
                'transfers.from_amount',
                'transfers.fee',
                'from_accounts.name as from_account',
                'to_accounts.name as to_account',
                'from_currencies.exchange_rate as from_rate',
            ]);

        return [
            'currency' => $this->anchorCode($userId),
            'truncated_at' => $rows->count() === $limit ? $limit : null,
            'transfers' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'date' => Carbon::parse($row->transfer_date)->toDateString(),
                'description' => $row->description,
                'from_account' => $row->from_account,
                'to_account' => $row->to_account,
                'amount' => $this->money((float) $row->from_amount * (float) ($row->from_rate ?? 1)),
                'fee' => $this->money((float) $row->fee * (float) ($row->from_rate ?? 1)),
            ])->all(),
        ];
    }

    public function getBudgetStatus(string $userId): array
    {
        $budgets = $this->budgets->list($userId, null, null)->load('category');

        return [
            'currency' => $this->anchorCode($userId),
            'budgets' => $budgets->map(fn ($budget) => [
                'category' => $budget->category?->name,
                'period_type' => $budget->period_type,
                'limit' => $budget->amount,
                'spent' => $budget->spent,
                'remaining' => $budget->remaining,
                'over_budget' => (float) $budget->remaining < 0,
            ])->all(),
        ];
    }

    public function getLiabilities(string $userId): array
    {
        $liabilities = $this->liabilities->list($userId, null, null)->load('userCurrency.currency');

        $outstanding = 0.0;
        $rows = [];

        foreach ($liabilities as $liability) {
            $rate = (float) ($liability->userCurrency?->exchange_rate ?? 1);
            $remaining = (float) $liability->remaining_balance * $rate;
            $outstanding += $remaining;

            $rows[] = [
                'id' => $liability->id,
                'name' => $liability->name,
                'type' => $liability->type,
                'principal' => $this->money((float) $liability->principal_amount * $rate),
                'paid' => $this->money((float) $liability->paid_amount * $rate),
                'remaining' => $this->money($remaining),
                'due_date' => $liability->due_date?->toDateString(),
                'is_settled' => (bool) $liability->is_settled,
            ];
        }

        return [
            'currency' => $this->anchorCode($userId),
            'liabilities' => $rows,
            'total_outstanding' => $this->money($outstanding),
        ];
    }

    /**
     * The user's anchor currency code, used to label every figure the tools return.
     */
    public function anchorCode(string $userId): ?string
    {
        return UserCurrency::where('user_id', $userId)
            ->where('is_anchor', true)
            ->with('currency')
            ->first()?->currency?->code;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRange(array $args): array
    {
        $start = Carbon::parse($args['start_date'] ?? Carbon::now()->startOfMonth())->toDateString();
        $end = Carbon::parse($args['end_date'] ?? Carbon::now())->toDateString();

        return $start <= $end ? [$start, $end] : [$end, $start];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
