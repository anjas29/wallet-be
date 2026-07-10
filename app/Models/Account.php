<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'user_currency_id',
        'name',
        'type',
        'color',
        'initial_balance',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userCurrency(): BelongsTo
    {
        return $this->belongsTo(UserCurrency::class);
    }
}
