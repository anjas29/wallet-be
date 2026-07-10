<?php

namespace App\Services;

use App\Http\Resources\AccountResource;
use App\Http\Resources\LiabilityPaymentResource;
use App\Http\Resources\LiabilityResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransferResource;
use App\Http\Resources\UserCurrencyResource;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SyncService
{
    private const ENTITIES = ['account', 'transaction', 'user_currency', 'transfer', 'liability', 'liability_payment'];

    private const OPS = ['create', 'update', 'delete'];

    public function __construct(
        private AccountService $accounts,
        private TransactionService $transactions,
        private UserCurrencyService $userCurrencies,
        private TransferService $transfers,
        private LiabilityService $liabilities,
        private LiabilityPaymentService $liabilityPayments,
    ) {}

    /**
     * Apply a batch of client changes, returning a per-item result list.
     *
     * @return list<array<string, mixed>>
     */
    public function apply(User $user, array $changes): array
    {
        $results = [];

        foreach ($changes as $change) {
            try {
                $results[] = $this->applyChange($user, $change);
            } catch (ValidationException $e) {
                $results[] = $this->failed($change, [
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ]);
            } catch (\Throwable $e) {
                $results[] = $this->failed($change, [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    private function applyChange(User $user, array $change): array
    {
        $entity = $change['entity'] ?? null;
        $op = $change['op'] ?? null;
        $id = $change['id'] ?? null;

        if (! in_array($entity, self::ENTITIES, true)) {
            throw ValidationException::withMessages(['changes' => ['Unsupported entity.']]);
        }

        if (! in_array($op, self::OPS, true)) {
            throw ValidationException::withMessages(['changes' => ['Unsupported operation.']]);
        }

        if (! $id) {
            throw ValidationException::withMessages(['changes' => ['A record id is required.']]);
        }

        [$service, $resource] = $this->resolve($entity);

        if ($op === 'delete') {
            $service->delete($user, $id);

            return $this->applied($change, $entity, $id, null);
        }

        $model = $service->createOrUpdate($user, $id, $op, $change['data'] ?? []);

        return $this->applied($change, $entity, $id, new $resource($model));
    }

    /**
     * @return array{0: object, 1: class-string}
     */
    private function resolve(string $entity): array
    {
        return match ($entity) {
            'account' => [$this->accounts, AccountResource::class],
            'transaction' => [$this->transactions, TransactionResource::class],
            'user_currency' => [$this->userCurrencies, UserCurrencyResource::class],
            'transfer' => [$this->transfers, TransferResource::class],
            'liability' => [$this->liabilities, LiabilityResource::class],
            'liability_payment' => [$this->liabilityPayments, LiabilityPaymentResource::class],
        };
    }

    private function applied(array $change, string $entity, string $id, mixed $record): array
    {
        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $id,
            'entity' => $entity,
            'status' => 'applied',
            'record' => $record,
        ];
    }

    private function failed(array $change, array $error): array
    {
        return [
            'client_change_id' => $change['client_change_id'] ?? null,
            'id' => $change['id'] ?? null,
            'entity' => $change['entity'] ?? null,
            'status' => 'failed',
            'error' => $error,
        ];
    }
}
