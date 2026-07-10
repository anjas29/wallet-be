<?php

namespace App\Services;

use App\Models\Category;
use App\Services\Concerns\DeltaSyncQuery;
use Illuminate\Database\Eloquent\Collection;

class CategoryService
{
    use DeltaSyncQuery;

    public function list(?string $type, ?string $since, ?int $limit): Collection
    {
        $query = Category::query();

        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        return $this->listDelta($query, $since, $limit);
    }

    public function find(string $id, ?string $since): ?Category
    {
        return $this->findDelta(Category::query(), $id, $since);
    }
}
