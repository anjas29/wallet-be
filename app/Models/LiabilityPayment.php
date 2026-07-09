<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiabilityPayment extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'liability_id',
        'account_id',
        'amount',
        'payment_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    public function liability(): BelongsTo
    {
        return $this->belongsTo(Liability::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
