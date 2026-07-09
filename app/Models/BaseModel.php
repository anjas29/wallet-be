<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    use HasUlids;

    /**
     * Serialize all datetime attributes to ISO-8601 (UTC, e.g. 2026-07-09T07:00:00.000000Z).
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format(DateTimeInterface::ATOM);
    }
}
