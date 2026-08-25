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
        $existing = $op === 'update' ? Transfer::where('user_id', $user->id)->find($id) : null;

        $payload = ['user_id' => $user->id];

        // from/to accounts are required together on create. An update that touches neither
        // keeps the existing pair untouched; supplying either one re-validates the pair as a
        // whole, since inequality/ownership only makes sense checked together.
        if ($op === 'create' || array_key_exists('from_account_id', $data) || array_key_exists('to_account_id', $data)) {
            $fromId = array_key_exists('from_account_id', $data) ? $data['from_account_id'] : $existing?->from_account_id;
            $toId = array_key_exists('to_account_id', $data) ? $data['to_account_id'] : $existing?->to_account_id;

            $ownsFrom = Account::where('id', $fromId)->where('user_id', $user->id)->exists();
            $ownsTo = Account::where('id', $toId)->where('user_id', $user->id)->exists();

            if (! $ownsFrom || ! $ownsTo || $fromId === $toId) {
                throw ValidationException::withMessages([
                    'data' => ['The transfer accounts are invalid.'],
                ]);
            }

            $payload['from_account_id'] = $fromId;
            $payload['to_account_id'] = $toId;
        }

        foreach (['from_amount', 'to_amount', 'exchange_rate', 'description', 'transfer_date'] as $field) {
            if ($op === 'create' || array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] ?? null;
            }
        }

        if ($op === 'create' || array_key_exists('fee', $data)) {
            $payload['fee'] = $data['fee'] ?? '0';
        }

        return $this->upsertEntity(Transfer::class, $id, $op, $payload, $user->id);
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(Transfer::class, $id, $user->id);
    }
}
