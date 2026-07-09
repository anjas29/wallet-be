<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Liability extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'user_currency_id',
        'name',
        'type',
        'principal_amount',
        'interest_rate',
        'due_date',
        'notes',
        'is_settled',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'due_date' => 'date',
            'is_settled' => 'boolean',
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

    public function payments(): HasMany
    {
        return $this->hasMany(LiabilityPayment::class);
    }
}
