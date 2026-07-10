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
        $ownsLiability = Liability::where('id', $data['liability_id'] ?? null)->where('user_id', $user->id)->exists();
        $ownsAccount = Account::where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->exists();

        if (! $ownsLiability || ! $ownsAccount) {
            throw ValidationException::withMessages([
                'data' => ['The liability or account is invalid.'],
            ]);
        }

        if ($op === 'update' && ! $this->ownedQuery($user->id)->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'id' => ['Record not found.'],
            ]);
        }

        // userId is null: the table has no user_id column; ownership already enforced above.
        return $this->upsertEntity(LiabilityPayment::class, $id, $op, [
            'liability_id' => $data['liability_id'],
            'account_id' => $data['account_id'],
            'amount' => $data['amount'] ?? null,
            'payment_date' => $data['payment_date'] ?? null,
            'note' => $data['note'] ?? null,
        ], null);
    }

    public function delete(User $user, string $id): void
    {
        $payment = $this->ownedQuery($user->id)->whereKey($id)->first();
        $payment?->delete();
    }

    private function ownedQuery(string $userId): Builder
    {
        return LiabilityPayment::whereHas('liability', fn ($q) => $q->where('user_id', $userId));
    }
}
