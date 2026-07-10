<?php

namespace App\Services;

use App\Models\Liability;
use App\Models\User;
use App\Models\UserCurrency;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LiabilityService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        return $this->listDelta(Liability::where('user_id', $userId), $since, $limit);
    }

    public function find(string $userId, string $id, ?string $since): ?Liability
    {
        return $this->findDelta(Liability::where('user_id', $userId), $id, $since);
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): Liability
    {
        $ownsCurrency = UserCurrency::where('id', $data['user_currency_id'] ?? null)
            ->where('user_id', $user->id)
            ->exists();

        if (! $ownsCurrency) {
            throw ValidationException::withMessages([
                'data.user_currency_id' => ['The selected currency is invalid.'],
            ]);
        }

        return $this->upsertEntity(Liability::class, $id, $op, [
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
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(Liability::class, $id, $user->id);
    }
}
