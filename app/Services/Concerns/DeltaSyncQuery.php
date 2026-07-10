<?php

namespace App\Services\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared read helpers for delta-sync style queries: when `since` is given, return everything
 * changed after it (including soft-deleted rows so clients learn about deletions); otherwise
 * return only live rows.
 */
trait DeltaSyncQuery
{
    protected function listDelta(Builder $query, ?string $since, ?int $limit): Collection
    {
        $this->applyDelta($query, $since);

        if ($limit !== null) {
            $query->limit(min($limit, 500));
        }

        return $query->orderBy('updated_at')->orderBy('id')->get();
    }

    protected function findDelta(Builder $query, string $id, ?string $since): ?Model
    {
        $query->whereKey($id);

        $this->applyDelta($query, $since);

        return $query->first();
    }

    protected function applyDelta(Builder $query, ?string $since): void
    {
        if ($since === null || $since === '') {
            return;
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query->withTrashed();
        }

        $query->where('updated_at', '>', $since);
    }
}
