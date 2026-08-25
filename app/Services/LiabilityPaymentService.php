<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Liability;
use App\Models\LiabilityPayment;
use App\Models\User;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LiabilityPaymentService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    /**
     * liability_payments has no user_id column; ownership is scoped through the parent liability.
     */
    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        return $this->listDelta($this->ownedQuery($userId), $since, $limit);
    }

    public function find(string $userId, string $id, ?string $since): ?LiabilityPayment
    {
        return $this->findDelta($this->ownedQuery($userId), $id, $since);
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): LiabilityPayment
    {
        $payload = [];

        // liability_id/account_id are required on create, but optional on update: an omitted
        // field means "keep the current value", not "invalid".
        if ($op === 'create' || array_key_exists('liability_id', $data)) {
            if (! Liability::where('id', $data['liability_id'] ?? null)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'data' => ['The liability or account is invalid.'],
                ]);
            }

            $payload['liability_id'] = $data['liability_id'];
        }

        if ($op === 'create' || array_key_exists('account_id', $data)) {
            if (! Account::where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'data' => ['The liability or account is invalid.'],
                ]);
            }

            $payload['account_id'] = $data['account_id'];
        }

        if ($op === 'update' && ! $this->ownedQuery($user->id)->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'id' => ['Record not found.'],
            ]);
        }

        foreach (['amount', 'payment_date', 'note'] as $field) {
            if ($op === 'create' || array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] ?? null;
            }
        }

        // userId is null: the table has no user_id column; ownership already enforced above.
        return $this->upsertEntity(LiabilityPayment::class, $id, $op, $payload, null);
    }

    public function delete(User $user, string $id): void
    {
        // Deleting a payment must still succeed even if its parent liability was already
        // soft-deleted first in the same sync batch (or an earlier request) — otherwise the
        // client's own delete change silently no-ops and the payment is left unreachable.
        $payment = $this->ownedQuery($user->id, includeTrashedLiability: true)->whereKey($id)->first();
        $payment?->delete();
    }

    private function ownedQuery(string $userId, bool $includeTrashedLiability = false): Builder
    {
        return LiabilityPayment::whereHas('liability', function ($q) use ($userId, $includeTrashedLiability) {
            if ($includeTrashedLiability) {
                $q->withTrashed();
            }

            $q->where('user_id', $userId);
        });
    }
}
