<?php

namespace App\Services\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * Shared write helpers for sync-driven upserts of client-provided ULIDs. Enforces ownership,
 * revives soft-deleted rows, and refuses updates to non-existent records.
 */
trait PersistsEntities
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function upsertEntity(string $modelClass, string $id, string $op, array $payload, ?string $userId): Model
    {
        $query = $modelClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        $model = $query->find($id);

        if ($model && $userId !== null && $model->user_id !== $userId) {
            throw ValidationException::withMessages(['id' => ['Record not found.']]);
        }

        if (! $model) {
            if ($op === 'update') {
                throw ValidationException::withMessages(['id' => ['Record not found.']]);
            }

            $model = new $modelClass;
            $model->id = $id;
        }

        $model->fill($payload);

        if (method_exists($model, 'trashed') && $model->trashed()) {
            $model->deleted_at = null;
        }

        $model->save();

        return $model->refresh();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function softDeleteEntity(string $modelClass, string $id, string $userId): void
    {
        $model = $modelClass::where('id', $id)->where('user_id', $userId)->first();
        $model?->delete();
    }
}
