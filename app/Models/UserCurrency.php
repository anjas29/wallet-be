<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCurrency extends BaseModel
{
    protected $fillable = [
        'user_id',
        'currency_id',
        'exchange_rate',
        'is_anchor',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'is_anchor' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
