<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeleteRequest extends BaseModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'email',
        'user_id',
        'status',
        'processed_by',
        'processed_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The account the request resolved to, if any. Uses `withTrashed()` because processing the
     * request soft-deletes that user — without it the panel would lose the name it just deleted.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
