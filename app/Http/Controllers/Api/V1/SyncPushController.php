<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncPushController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'changes' => ['required', 'array'],
        ]);

        $results = [];
        $user = $request->user();

        foreach ($data['changes'] as $change) {
            try {
                $results[] = $this->applyChange($user, $change);
            } catch (ValidationException $e) {
                $results[] = [
                    'client_change_id' => $change['client_change_id'] ?? null,
                    'id' => $change['id'] ?? null,
                    'entity' => $change['entity'] ?? null,
                    'status' => 'failed',
                    'error' => [
                        'message' => 'Validation failed.',
                        'errors' => $e->errors(),
                    ],
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'client_change_id' => $change['client_change_id'] ?? null,
                    'id' => $change['id'] ?? null,
                    'entity' => $change['entity'] ?? null,
                    'status' => 'failed',
                    'error' => [
                        'message' => $e->getMessage(),
                    ],
                ];
            }
        }

        return response()->json([
            'results' => $results,
            'server_time' => now()->toISOString(),
        ]);
    }

    protected function applyChange($user, array $change): array
    {
        $entity = $change['entity'] ?? null;
        $op = $change['op'] ?? null;
        $id = $change['id'] ?? null;

        if (! in_array($entity, ['account', 'transaction', 'user_currency', 'transfer', 'liability', 'liability_payment'], true)) {
            throw ValidationException::withMessages([
                'changes' => ['Unsupported entity.'],
            ]);
        }

        if (! in_array($op, ['create', 'update', 'delete'], true)) {
            throw ValidationException::withMessages([
                'changes' => ['Unsupported operation.'],
            ]);
        }

        if (! $id) {
            throw ValidationException::withMessages([
                'changes' => ['A record id is required.'],
            ]);
        }

        return match ($entity) {
            'account' => $this->applyAccountChange($user, $change),
            'transaction' => $this->applyTransactionChange($user, $change),
            'user_currency' => $this->applyUserCurrencyChange($user, $change),
            'transfer' => $this->applyTransferChange($user, $change),
            'liability' => $this->applyLiabilityChange($user, $change),
            'liability_payment' => $this->applyLiabilityPaymentChange($user, $change),
        };
    }

    protected function applyAccountChange($user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];
        $op = $change['op'];

        if ($op === 'delete') {
            DB::table('accounts')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return $this->result($change, 'account', $id, 'applied');
        }

        $userCurrency = DB::table('user_currencies')
            ->where('id', $data['user_currency_id'] ?? null)
            ->where('user_id', $user->id)
            ->first();

        if (! $userCurrency) {
            throw ValidationException::withMessages([
                'data.user_currency_id' => ['The selected currency is invalid.'],
            ]);
        }

        $payload = [
            'user_id' => $user->id,
            'user_currency_id' => $data['user_currency_id'],
            'name' => $data['name'],
            'type' => $data['type'],
            'initial_balance' => $data['initial_balance'] ?? '0',
            'is_default' => (bool) ($data['is_default'] ?? false),
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        if ($op === 'create') {
            DB::table('accounts')->updateOrInsert(['id' => $id], array_merge($payload, ['created_at' => now()]));
        } else {
            DB::table('accounts')->where('id', $id)->where('user_id', $user->id)->update($payload);
        }

        if ($payload['is_default']) {
            DB::table('accounts')
                ->where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => 'account',
            'status' => 'applied',
            'record' => DB::table('accounts')->where('id', $id)->first(),
        ];
    }

    protected function applyTransactionChange($user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];
        $op = $change['op'];

        if ($op === 'delete') {
            DB::table('transactions')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return $this->result($change, 'transaction', $id, 'applied');
        }

        $account = DB::table('accounts')->where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->first();
        $category = DB::table('categories')->where('id', $data['category_id'] ?? null)->first();
        $currency = DB::table('currencies')->where('id', $data['currency_id'] ?? null)->first();

        if (! $account || ! $category || ! $currency) {
            throw ValidationException::withMessages([
                'data' => ['The selected account, category, or currency is invalid.'],
            ]);
        }

        $payload = [
            'user_id' => $user->id,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'currency_id' => $data['currency_id'],
            'exchange_rate_to_anchor' => $data['exchange_rate_to_anchor'] ?? '1',
            'type' => $data['type'] ?? 'expense',
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'transaction_date' => $data['transaction_date'],
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        if ($op === 'create') {
            DB::table('transactions')->updateOrInsert(['id' => $id], array_merge($payload, ['created_at' => now()]));
        } else {
            DB::table('transactions')->where('id', $id)->where('user_id', $user->id)->update($payload);
        }

        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => 'transaction',
            'status' => 'applied',
            'record' => DB::table('transactions')->where('id', $id)->first(),
        ];
    }

    protected function applyUserCurrencyChange($user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];
        $op = $change['op'];

        if ($op === 'delete') {
            throw ValidationException::withMessages([
                'changes' => ['Deleting user currencies is not supported.'],
            ]);
        }

        $currency = DB::table('currencies')->where('id', $data['currency_id'] ?? null)->first();
        if (! $currency) {
            throw ValidationException::withMessages([
                'data.currency_id' => ['The selected currency is invalid.'],
            ]);
        }

        $payload = [
            'user_id' => $user->id,
            'currency_id' => $data['currency_id'],
            'exchange_rate' => $data['exchange_rate'] ?? '1',
            'is_anchor' => (bool) ($data['is_anchor'] ?? false),
            'updated_at' => now(),
        ];

        if ($payload['is_anchor']) {
            DB::table('user_currencies')
                ->where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->update(['is_anchor' => false]);
        }

        if ($op === 'create') {
            DB::table('user_currencies')->updateOrInsert(['id' => $id], array_merge($payload, ['created_at' => now()]));
        } else {
            DB::table('user_currencies')->where('id', $id)->where('user_id', $user->id)->update($payload);
        }

        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => 'user_currency',
            'status' => 'applied',
            'record' => DB::table('user_currencies')->where('id', $id)->first(),
        ];
    }

    protected function applyTransferChange($user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];
        $op = $change['op'];

        if ($op === 'delete') {
            DB::table('transfers')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return $this->result($change, 'transfer', $id, 'applied');
        }

        $fromAccount = DB::table('accounts')->where('id', $data['from_account_id'] ?? null)->where('user_id', $user->id)->first();
        $toAccount = DB::table('accounts')->where('id', $data['to_account_id'] ?? null)->where('user_id', $user->id)->first();

        if (! $fromAccount || ! $toAccount || ($data['from_account_id'] ?? null) === ($data['to_account_id'] ?? null)) {
            throw ValidationException::withMessages([
                'data' => ['The transfer accounts are invalid.'],
            ]);
        }

        $payload = [
            'user_id' => $user->id,
            'from_account_id' => $data['from_account_id'],
            'to_account_id' => $data['to_account_id'],
            'from_amount' => $data['from_amount'],
            'to_amount' => $data['to_amount'],
            'exchange_rate' => $data['exchange_rate'] ?? null,
            'fee' => $data['fee'] ?? '0',
            'description' => $data['description'] ?? null,
            'transfer_date' => $data['transfer_date'],
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        if ($op === 'create') {
            DB::table('transfers')->updateOrInsert(['id' => $id], array_merge($payload, ['created_at' => now()]));
        } else {
            DB::table('transfers')->where('id', $id)->where('user_id', $user->id)->update($payload);
        }

        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => 'transfer',
            'status' => 'applied',
            'record' => DB::table('transfers')->where('id', $id)->first(),
        ];
    }

    protected function applyLiabilityChange($user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];
        $op = $change['op'];

        if ($op === 'delete') {
            DB::table('liabilities')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return $this->result($change, 'liability', $id, 'applied');
        }

        $userCurrency = DB::table('user_currencies')->where('id', $data['user_currency_id'] ?? null)->where('user_id', $user->id)->first();
        if (! $userCurrency) {
            throw ValidationException::withMessages([
                'data.user_currency_id' => ['The selected currency is invalid.'],
            ]);
        }

        $payload = [
            'user_id' => $user->id,
            'user_currency_id' => $data['user_currency_id'],
            'name' => $data['name'],
            'type' => $data['type'],
            'principal_amount' => $data['principal_amount'],
            'interest_rate' => $data['interest_rate'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_settled' => (bool) ($data['is_settled'] ?? false),
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        if ($op === 'create') {
            DB::table('liabilities')->updateOrInsert(['id' => $id], array_merge($payload, ['created_at' => now()]));
        } else {
            DB::table('liabilities')->where('id', $id)->where('user_id', $user->id)->update($payload);
        }

        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => 'liability',
            'status' => 'applied',
            'record' => DB::table('liabilities')->where('id', $id)->first(),
        ];
    }

    protected function applyLiabilityPaymentChange($user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];
        $op = $change['op'];

        if ($op === 'delete') {
            DB::table('liability_payments')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return $this->result($change, 'liability_payment', $id, 'applied');
        }

        $liability = DB::table('liabilities')->where('id', $data['liability_id'] ?? null)->where('user_id', $user->id)->first();
        $account = DB::table('accounts')->where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->first();

        if (! $liability || ! $account) {
            throw ValidationException::withMessages([
                'data' => ['The liability or account is invalid.'],
            ]);
        }

        $payload = [
            'liability_id' => $data['liability_id'],
            'account_id' => $data['account_id'],
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'note' => $data['note'] ?? null,
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        if ($op === 'create') {
            DB::table('liability_payments')->updateOrInsert(['id' => $id], array_merge($payload, ['created_at' => now()]));
        } else {
            DB::table('liability_payments')->where('id', $id)->update($payload);
        }

        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => 'liability_payment',
            'status' => 'applied',
            'record' => DB::table('liability_payments')->where('id', $id)->first(),
        ];
    }

    protected function result(array $change, string $entity, string $id, string $status): array
    {
        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => $entity,
            'status' => $status,
        ];
    }
}
