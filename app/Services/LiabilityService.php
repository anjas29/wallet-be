<?php

namespace App\Services;

use App\Models\Liability;
use App\Models\LiabilityPayment;
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
        $liabilities = $this->listDelta(Liability::where('user_id', $userId), $since, $limit);

        return $this->attachRemainingBalances($liabilities);
    }

    public function find(string $userId, string $id, ?string $since): ?Liability
    {
        $liability = $this->findDelta(Liability::where('user_id', $userId), $id, $since);

        if ($liability !== null) {
            $this->attachRemainingBalances(new Collection([$liability]));
        }

        return $liability;
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): Liability
    {
        $payload = ['user_id' => $user->id];

        // user_currency_id is required on create, but optional on update: an omitted
        // field means "keep the current value", not "invalid".
        if ($op === 'create' || array_key_exists('user_currency_id', $data)) {
            if (! UserCurrency::where('id', $data['user_currency_id'] ?? null)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'data.user_currency_id' => ['The selected currency is invalid.'],
                ]);
            }

            $payload['user_currency_id'] = $data['user_currency_id'];
        }

        foreach (['name', 'type', 'principal_amount', 'interest_rate', 'due_date', 'notes'] as $field) {
            if ($op === 'create' || array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] ?? null;
            }
        }

        if ($op === 'create' || array_key_exists('is_settled', $data)) {
            $payload['is_settled'] = (bool) ($data['is_settled'] ?? false);
        }

        return $this->upsertEntity(Liability::class, $id, $op, $payload, $user->id);
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(Liability::class, $id, $user->id);
    }

    /**
     * Attach derived `paid_amount`/`remaining_balance` (in the liability's own currency) to each
     * model. Uses a fixed number of grouped aggregate queries regardless of liability count.
     */
    public function attachRemainingBalances(Collection $liabilities): Collection
    {
        if ($liabilities->isEmpty()) {
            return $liabilities;
        }

        $ids = $liabilities->pluck('id')->all();
        $paid = $this->remainingBalancesFor($ids);

        foreach ($liabilities as $liability) {
            $paidAmount = $paid[$liability->id] ?? '0.00';
            $remaining = number_format(max(0, (float) $liability->principal_amount - (float) $paidAmount), 2, '.', '');

            $liability->setAttribute('paid_amount', $paidAmount);
            $liability->setAttribute('remaining_balance', $remaining);
        }

        return $liabilities;
    }

    /**
     * @param  list<string>  $liabilityIds
     * @return array<string, string> liability id => total paid (2-dp string)
     */
    public function remainingBalancesFor(array $liabilityIds): array
    {
        $payments = LiabilityPayment::query()
            ->whereIn('liability_id', $liabilityIds)
            ->groupBy('liability_id')
            ->selectRaw('liability_id, SUM(amount) as total')
            ->pluck('total', 'liability_id');

        $result = [];
        foreach ($liabilityIds as $id) {
            $result[$id] = number_format((float) ($payments[$id] ?? 0), 2, '.', '');
        }

        return $result;
    }
}
