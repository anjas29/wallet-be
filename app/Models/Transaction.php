<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'currency_id',
        'exchange_rate_to_anchor',
        'type',
        'amount',
        'description',
        'transaction_date',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate_to_anchor' => 'decimal:6',
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
