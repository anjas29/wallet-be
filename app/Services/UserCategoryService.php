<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserCategory;
use App\Services\Concerns\DeltaSyncQuery;
use App\Services\Concerns\PersistsEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class UserCategoryService
{
    use DeltaSyncQuery;
    use PersistsEntities;

    public function list(string $userId, ?string $type, ?string $since, ?int $limit): Collection
    {
        $query = UserCategory::where('user_id', $userId);

        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        return $this->listDelta($query, $since, $limit);
    }

    public function find(string $userId, string $id, ?string $since): ?UserCategory
    {
        return $this->findDelta(UserCategory::where('user_id', $userId), $id, $since);
    }

    /**
     * REST create: server generates the ULID (unlike sync, where the client provides it).
     */
    public function create(User $user, array $data): UserCategory
    {
        return $this->createOrUpdate($user, (string) Str::ulid(), 'create', $data);
    }

    public function createOrUpdate(User $user, string $id, string $op, array $data): UserCategory
    {
        $payload = [
            'user_id' => $user->id,
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? null,
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
        ];

        return $this->upsertEntity(UserCategory::class, $id, $op, $payload, $user->id);
    }

    public function delete(User $user, string $id): void
    {
        $this->softDeleteEntity(UserCategory::class, $id, $user->id);
    }
}
