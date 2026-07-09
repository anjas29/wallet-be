<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Currency extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
        ];
    }
}
