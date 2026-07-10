<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TransferService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        return $this->listDelta(Transfer::where('user_id', $userId), $since, $limit);
    }

    public function find(string $userId, string $id, ?string $since): ?Transfer
    {
        return $this->findDelta(Transfer::where('user_id', $userId), $id, $since);
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): Transfer
    {
        $fromId = $data['from_account_id'] ?? null;
        $toId = $data['to_account_id'] ?? null;

        $ownsFrom = Account::where('id', $fromId)->where('user_id', $user->id)->exists();
        $ownsTo = Account::where('id', $toId)->where('user_id', $user->id)->exists();

        if (! $ownsFrom || ! $ownsTo || $fromId === $toId) {
            throw ValidationException::withMessages([
                'data' => ['The transfer accounts are invalid.'],
            ]);
        }

        return $this->upsertEntity(Transfer::class, $id, $op, [
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
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(Transfer::class, $id, $user->id);
    }
}
