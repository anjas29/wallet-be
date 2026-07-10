<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\User;
use App\Models\UserCurrency;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class UserCurrencyService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        return $this->listDelta(UserCurrency::where('user_id', $userId), $since, $limit);
    }

    public function find(string $userId, string $id, ?string $since): ?UserCurrency
    {
        return $this->findDelta(UserCurrency::where('user_id', $userId), $id, $since);
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): UserCurrency
    {
        if (! Currency::where('id', $data['currency_id'] ?? null)->exists()) {
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

        return $this->upsertEntity(UserCurrency::class, $id, $op, [
            'user_id' => $user->id,
            'currency_id' => $data['currency_id'],
            'exchange_rate' => $data['exchange_rate'] ?? '1',
            'is_anchor' => $isAnchor,
        ], $user->id);
    }

    public function delete(User $user, string $id): void
    {
        throw ValidationException::withMessages([
            'changes' => ['Deleting user currencies is not supported.'],
        ]);
    }
}
