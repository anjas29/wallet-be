<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        // Eager-load for the resource's derived currency_id (transaction is in its account's currency).
        return $this->listDelta(
            Transaction::where('user_id', $userId)->with('account.userCurrency'),
            $since,
            $limit,
        );
    }

    public function find(string $userId, string $id, ?string $since): ?Transaction
    {
        return $this->findDelta(
            Transaction::where('user_id', $userId)->with('account.userCurrency'),
            $id,
            $since,
        );
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): Transaction
    {
        $ownsAccount = Account::where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->exists();
        $categoryExists = Category::where('id', $data['category_id'] ?? null)->exists();

        if (! $ownsAccount || ! $categoryExists) {
            throw ValidationException::withMessages([
                'data' => ['The selected account or category is invalid.'],
            ]);
        }

        $transaction = $this->upsertEntity(Transaction::class, $id, $op, [
            'user_id' => $user->id,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'exchange_rate_to_anchor' => $data['exchange_rate_to_anchor'] ?? '1',
            'type' => $data['type'] ?? 'expense',
            'amount' => $data['amount'] ?? null,
            'description' => $data['description'] ?? null,
            'transaction_date' => $data['transaction_date'] ?? null,
        ], $user->id);

        return $transaction->load('account.userCurrency');
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(Transaction::class, $id, $user->id);
    }
}
