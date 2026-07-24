<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserCategory extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'icon',
        'color',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
