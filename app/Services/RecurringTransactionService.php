<?php

namespace App\Services;

use App\Models\Account;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RecurringTransactionService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    public function list(string $userId, ?string $since, ?int $limit): Collection
    {
        return $this->listDelta(RecurringTransaction::where('user_id', $userId), $since, $limit);
    }

    public function find(string $userId, string $id, ?string $since): ?RecurringTransaction
    {
        return $this->findDelta(RecurringTransaction::where('user_id', $userId), $id, $since);
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): RecurringTransaction
    {
        $ownsAccount = Account::where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->exists();
        $ownsCategory = UserCategory::where('id', $data['category_id'] ?? null)->where('user_id', $user->id)->exists();

        if (! $ownsAccount || ! $ownsCategory) {
            throw ValidationException::withMessages([
                'data' => ['The selected account or category is invalid.'],
            ]);
        }

        if (! in_array($data['frequency'] ?? null, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            throw ValidationException::withMessages([
                'data.frequency' => ['Invalid frequency.'],
            ]);
        }

        if (empty($data['start_date'] ?? null)) {
            throw ValidationException::withMessages([
                'data.start_date' => ['A start date is required.'],
            ]);
        }

        $payload = [
            'user_id' => $user->id,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'],
            'amount' => $data['amount'] ?? null,
            'description' => $data['description'] ?? null,
            'frequency' => $data['frequency'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        // next_run_date is server-owned bookkeeping, never accepted from the client.
        $payload['next_run_date'] = $op === 'create'
            ? $payload['start_date']
            : $this->resyncNextRunDate($user->id, $id, $payload);

        return $this->upsertEntity(RecurringTransaction::class, $id, $op, $payload, $user->id);
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(RecurringTransaction::class, $id, $user->id);
    }

    /**
     * On update, resync next_run_date only when the edit would otherwise leave it stale: a
     * changed start_date, or resuming a paused template whose next_run_date fell into the past.
     * Otherwise leave the scheduler's bookkeeping untouched.
     */
    private function resyncNextRunDate(string $userId, string $id, array $payload): ?string
    {
        $existing = RecurringTransaction::where('user_id', $userId)->find($id);

        if ($existing === null) {
            return $payload['start_date'];
        }

        $startChanged = (string) $existing->start_date?->toDateString() !== (string) $payload['start_date'];
        $resuming = ! $existing->is_active && $payload['is_active'];
        $staleOnResume = $resuming && $existing->next_run_date !== null && $existing->next_run_date->isPast();

        if ($startChanged || $staleOnResume) {
            return max($payload['start_date'], now()->toDateString());
        }

        return $existing->next_run_date?->toDateString();
    }
}
