<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Budget extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'category_id',
        'amount',
        'period_type',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(UserCategory::class, 'category_id');
    }
}
