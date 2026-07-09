<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Http\Resources\LiabilityPaymentResource;
use App\Http\Resources\LiabilityResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransferResource;
use App\Http\Resources\UserCurrencyResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Liability;
use App\Models\LiabilityPayment;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
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
                $results[] = $this->failed($change, [
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ]);
            } catch (\Throwable $e) {
                $results[] = $this->failed($change, [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->success([
            'results' => $results,
            'server_time' => now()->toISOString(),
        ]);
    }

    protected function applyChange(User $user, array $change): array
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

    protected function applyAccountChange(User $user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];

        if ($change['op'] === 'delete') {
            $this->softDelete(Account::class, $id, $user->id);

            return $this->applied($change, 'account', $id);
        }

        $ownsCurrency = UserCurrency::where('id', $data['user_currency_id'] ?? null)
            ->where('user_id', $user->id)
            ->exists();

        if (! $ownsCurrency) {
            throw ValidationException::withMessages([
                'data.user_currency_id' => ['The selected currency is invalid.'],
            ]);
        }

        $account = $this->upsert(Account::class, $id, $change['op'], [
            'user_id' => $user->id,
            'user_currency_id' => $data['user_currency_id'],
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? null,
            'initial_balance' => $data['initial_balance'] ?? '0',
            'is_default' => (bool) ($data['is_default'] ?? false),
        ], $user->id);

        if ($account->is_default) {
            Account::where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        return $this->applied($change, 'account', $id, new AccountResource($account));
    }

    protected function applyTransactionChange(User $user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];

        if ($change['op'] === 'delete') {
            $this->softDelete(Transaction::class, $id, $user->id);

            return $this->applied($change, 'transaction', $id);
        }

        $ownsAccount = Account::where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->exists();
        $categoryExists = Category::where('id', $data['category_id'] ?? null)->exists();
        $currencyExists = Currency::where('id', $data['currency_id'] ?? null)->exists();

        if (! $ownsAccount || ! $categoryExists || ! $currencyExists) {
            throw ValidationException::withMessages([
                'data' => ['The selected account, category, or currency is invalid.'],
            ]);
        }

        $transaction = $this->upsert(Transaction::class, $id, $change['op'], [
            'user_id' => $user->id,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'currency_id' => $data['currency_id'],
            'exchange_rate_to_anchor' => $data['exchange_rate_to_anchor'] ?? '1',
            'type' => $data['type'] ?? 'expense',
            'amount' => $data['amount'] ?? null,
            'description' => $data['description'] ?? null,
            'transaction_date' => $data['transaction_date'] ?? null,
        ], $user->id);

        return $this->applied($change, 'transaction', $id, new TransactionResource($transaction));
    }

    protected function applyUserCurrencyChange(User $user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];

        if ($change['op'] === 'delete') {
            throw ValidationException::withMessages([
                'changes' => ['Deleting user currencies is not supported.'],
            ]);
        }

        $currencyExists = Currency::where('id', $data['currency_id'] ?? null)->exists();

        if (! $currencyExists) {
            throw ValidationException::withMessages([
                'data.currency_id' => ['The selected currency is invalid.'],
            ]);
        }

        $isAnchor = (bool) ($data['is_anchor'] ?? false);

        if ($isAnchor) {
            UserCurrency::where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->update(['is_anchor' => false]);
        }

        $userCurrency = $this->upsert(UserCurrency::class, $id, $change['op'], [
            'user_id' => $user->id,
            'currency_id' => $data['currency_id'],
            'exchange_rate' => $data['exchange_rate'] ?? '1',
            'is_anchor' => $isAnchor,
        ], $user->id);

        return $this->applied($change, 'user_currency', $id, new UserCurrencyResource($userCurrency));
    }

    protected function applyTransferChange(User $user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];

        if ($change['op'] === 'delete') {
            $this->softDelete(Transfer::class, $id, $user->id);

            return $this->applied($change, 'transfer', $id);
        }

        $fromId = $data['from_account_id'] ?? null;
        $toId = $data['to_account_id'] ?? null;

        $ownsFrom = Account::where('id', $fromId)->where('user_id', $user->id)->exists();
        $ownsTo = Account::where('id', $toId)->where('user_id', $user->id)->exists();

        if (! $ownsFrom || ! $ownsTo || $fromId === $toId) {
            throw ValidationException::withMessages([
                'data' => ['The transfer accounts are invalid.'],
            ]);
        }

        $transfer = $this->upsert(Transfer::class, $id, $change['op'], [
            'user_id' => $user->id,
            'from_account_id' => $fromId,
            'to_account_id' => $toId,
            'from_amount' => $data['from_amount'] ?? null,
            'to_amount' => $data['to_amount'] ?? null,
            'exchange_rate' => $data['exchange_rate'] ?? null,
            'fee' => $data['fee'] ?? '0',
            'description' => $data['description'] ?? null,
            'transfer_date' => $data['transfer_date'] ?? null,
        ], $user->id);

        return $this->applied($change, 'transfer', $id, new TransferResource($transfer));
    }

    protected function applyLiabilityChange(User $user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];

        if ($change['op'] === 'delete') {
            $this->softDelete(Liability::class, $id, $user->id);

            return $this->applied($change, 'liability', $id);
        }

        $ownsCurrency = UserCurrency::where('id', $data['user_currency_id'] ?? null)
            ->where('user_id', $user->id)
            ->exists();

        if (! $ownsCurrency) {
            throw ValidationException::withMessages([
                'data.user_currency_id' => ['The selected currency is invalid.'],
            ]);
        }

        $liability = $this->upsert(Liability::class, $id, $change['op'], [
            'user_id' => $user->id,
            'user_currency_id' => $data['user_currency_id'],
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? null,
            'principal_amount' => $data['principal_amount'] ?? null,
            'interest_rate' => $data['interest_rate'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_settled' => (bool) ($data['is_settled'] ?? false),
        ], $user->id);

        return $this->applied($change, 'liability', $id, new LiabilityResource($liability));
    }

    protected function applyLiabilityPaymentChange(User $user, array $change): array
    {
        $data = $change['data'] ?? [];
        $id = $change['id'];

        // liability_payments has no user_id column; ownership is enforced via the parent liability.
        if ($change['op'] === 'delete') {
            $payment = LiabilityPayment::where('id', $id)
                ->whereHas('liability', fn ($q) => $q->where('user_id', $user->id))
                ->first();
            $payment?->delete();

            return $this->applied($change, 'liability_payment', $id);
        }

        $ownsLiability = Liability::where('id', $data['liability_id'] ?? null)->where('user_id', $user->id)->exists();
        $ownsAccount = Account::where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->exists();

        if (! $ownsLiability || ! $ownsAccount) {
            throw ValidationException::withMessages([
                'data' => ['The liability or account is invalid.'],
            ]);
        }

        // On update, ensure the existing payment belongs to one of the user's liabilities.
        if ($change['op'] === 'update') {
            $ownsPayment = LiabilityPayment::where('id', $id)
                ->whereHas('liability', fn ($q) => $q->where('user_id', $user->id))
                ->exists();

            if (! $ownsPayment) {
                throw ValidationException::withMessages([
                    'id' => ['Record not found.'],
                ]);
            }
        }

        $payment = $this->upsert(LiabilityPayment::class, $id, $change['op'], [
            'liability_id' => $data['liability_id'],
            'account_id' => $data['account_id'],
            'amount' => $data['amount'] ?? null,
            'payment_date' => $data['payment_date'] ?? null,
            'note' => $data['note'] ?? null,
        ], null);

        return $this->applied($change, 'liability_payment', $id, new LiabilityPaymentResource($payment));
    }

    /**
     * Upsert a client-provided record by its ULID. Enforces ownership when $userId is given,
     * revives soft-deleted rows, and refuses updates to non-existent records.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function upsert(string $modelClass, string $id, string $op, array $payload, ?string $userId): Model
    {
        $query = $modelClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        $model = $query->find($id);

        if ($model && $userId !== null && $model->user_id !== $userId) {
            throw ValidationException::withMessages(['id' => ['Record not found.']]);
        }

        if (! $model) {
            if ($op === 'update') {
                throw ValidationException::withMessages(['id' => ['Record not found.']]);
            }

            $model = new $modelClass;
            $model->id = $id;
        }

        $model->fill($payload);

        if (method_exists($model, 'trashed') && $model->trashed()) {
            $model->deleted_at = null;
        }

        $model->save();

        return $model->refresh();
    }

    /**
     * Soft-delete a user-owned record if it exists. No-op when absent (idempotent sync).
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function softDelete(string $modelClass, string $id, string $userId): void
    {
        $model = $modelClass::where('id', $id)->where('user_id', $userId)->first();
        $model?->delete();
    }

    protected function applied(array $change, string $entity, string $id, mixed $record = null): array
    {
        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => $entity,
            'status' => 'applied',
            'record' => $record,
        ];
    }

    protected function failed(array $change, array $error): array
    {
        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $change['id'] ?? null,
            'entity' => $change['entity'] ?? null,
            'status' => 'failed',
            'error' => $error,
        ];
    }
}
