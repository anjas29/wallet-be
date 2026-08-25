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
        $payload = ['user_id' => $user->id];

        // account_id/category_id/frequency/start_date are required on create, but optional on
        // update: an omitted field means "keep the current value", not "invalid".
        if ($op === 'create' || array_key_exists('account_id', $data)) {
            if (! Account::where('id', $data['account_id'] ?? null)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'data' => ['The selected account or category is invalid.'],
                ]);
            }

            $payload['account_id'] = $data['account_id'];
        }

        if ($op === 'create' || array_key_exists('category_id', $data)) {
            if (! UserCategory::where('id', $data['category_id'] ?? null)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages([
                    'data' => ['The selected account or category is invalid.'],
                ]);
            }

            $payload['category_id'] = $data['category_id'];
        }

        if ($op === 'create' || array_key_exists('frequency', $data)) {
            if (! in_array($data['frequency'] ?? null, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
                throw ValidationException::withMessages([
                    'data.frequency' => ['Invalid frequency.'],
                ]);
            }

            $payload['frequency'] = $data['frequency'];
        }

        if ($op === 'create' || array_key_exists('start_date', $data)) {
            if (empty($data['start_date'] ?? null)) {
                throw ValidationException::withMessages([
                    'data.start_date' => ['A start date is required.'],
                ]);
            }

            $payload['start_date'] = $data['start_date'];
        }

        foreach (['amount', 'description', 'end_date'] as $field) {
            if ($op === 'create' || array_key_exists($field, $data)) {
                $payload[$field] = $data[$field] ?? null;
            }
        }

        if ($op === 'create' || array_key_exists('is_active', $data)) {
            $payload['is_active'] = (bool) ($data['is_active'] ?? true);
        }

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
            return $payload['start_date'] ?? null;
        }

        // A partial update may omit start_date/is_active entirely; fall back to the stored
        // values so the resync decision reflects the record's actual resulting state.
        $startDate = $payload['start_date'] ?? $existing->start_date?->toDateString();
        $isActive = array_key_exists('is_active', $payload) ? $payload['is_active'] : $existing->is_active;

        $startChanged = (string) $existing->start_date?->toDateString() !== (string) $startDate;
        $resuming = ! $existing->is_active && $isActive;
        $staleOnResume = $resuming && $existing->next_run_date !== null && $existing->next_run_date->isPast();

        if ($startChanged || $staleOnResume) {
            return max($startDate, now()->toDateString());
        }

        return $existing->next_run_date?->toDateString();
    }
}
