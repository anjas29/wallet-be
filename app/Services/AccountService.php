<?php

namespace App\Services;

use App\Models\Account;
use App\Models\LiabilityPayment;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserCurrency;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class AccountService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        $accounts = $this->listDelta(Account::where('user_id', $userId), $since, $limit);

        return $this->attachBalances($userId, $accounts);
    }

    public function find(string $userId, string $id, ?string $since): ?Account
    {
        $account = $this->findDelta(Account::where('user_id', $userId), $id, $since);

        if ($account !== null) {
            $this->attachBalances($userId, new Collection([$account]));
        }

        return $account;
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): Account
    {
        $ownsCurrency = UserCurrency::where('id', $data['user_currency_id'] ?? null)
            ->where('user_id', $user->id)
            ->exists();

        if (! $ownsCurrency) {
            throw ValidationException::withMessages([
                'data.user_currency_id' => ['The selected currency is invalid.'],
            ]);
        }

        $account = $this->upsertEntity(Account::class, $id, $op, [
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

        return $account;
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(Account::class, $id, $user->id);
    }

    /**
     * Attach a derived `balance` attribute (in the account's own currency) to each model.
     * Uses a fixed number of grouped aggregate queries regardless of account count.
     */
    public function attachBalances(string $userId, Collection $accounts): Collection
    {
        if ($accounts->isEmpty()) {
            return $accounts;
        }

        $ids = $accounts->pluck('id')->all();
        $balances = $this->balancesFor($userId, $ids);

        foreach ($accounts as $account) {
            $account->setAttribute('balance', $balances[$account->id] ?? $account->initial_balance);
        }

        return $accounts;
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, string> account id => balance (2-dp string)
     */
    public function balancesFor(string $userId, array $accountIds): array
    {
        $income = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('type', 'income')
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(amount) as total')
            ->pluck('total', 'account_id');

        $expense = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('type', 'expense')
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(amount) as total')
            ->pluck('total', 'account_id');

        $transfersIn = Transfer::query()
            ->whereIn('to_account_id', $accountIds)
            ->groupBy('to_account_id')
            ->selectRaw('to_account_id, SUM(to_amount) as total')
            ->pluck('total', 'to_account_id');

        $transfersOut = Transfer::query()
            ->whereIn('from_account_id', $accountIds)
            ->groupBy('from_account_id')
            ->selectRaw('from_account_id, SUM(from_amount + fee) as total')
            ->pluck('total', 'from_account_id');

        $payments = LiabilityPayment::query()
            ->whereIn('account_id', $accountIds)
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(amount) as total')
            ->pluck('total', 'account_id');

        $initials = Account::whereIn('id', $accountIds)->pluck('initial_balance', 'id');

        $balances = [];

        foreach ($accountIds as $id) {
            $balance = (float) ($initials[$id] ?? 0)
                + (float) ($income[$id] ?? 0)
                - (float) ($expense[$id] ?? 0)
                + (float) ($transfersIn[$id] ?? 0)
                - (float) ($transfersOut[$id] ?? 0)
                - (float) ($payments[$id] ?? 0);

            $balances[$id] = number_format($balance, 2, '.', '');
        }

        return $balances;
    }
}
