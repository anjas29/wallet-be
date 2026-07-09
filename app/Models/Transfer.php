<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transfer extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'from_account_id',
        'to_account_id',
        'from_amount',
        'to_amount',
        'exchange_rate',
        'fee',
        'description',
        'transfer_date',
    ];

    protected function casts(): array
    {
        return [
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'fee' => 'decimal:2',
            'transfer_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }
}
