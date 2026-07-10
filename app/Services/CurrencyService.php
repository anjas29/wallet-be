<?php

namespace App\Services;

use App\Models\Currency;
use App\Services\Concerns\DeltaSyncQuery;
use Illuminate\Database\Eloquent\Collection;

class CurrencyService
{
    use DeltaSyncQuery;

    public function list(?string $since, ?int $limit): Collection
    {
        return $this->listDelta(Currency::query(), $since, $limit);
    }

    public function find(string $id, ?string $since): ?Currency
    {
        return $this->findDelta(Currency::query(), $id, $since);
    }
}
