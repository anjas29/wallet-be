<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends BaseModel
{
    protected $fillable = [
        'user_id',
        'family_id',
        'token_hash',
        'device_id',
        'device_name',
        'ip_address',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
