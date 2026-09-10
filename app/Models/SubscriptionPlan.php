<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_amount',
        'interval',
        'features',
        'stripe_price_id',
        'is_active',
        'sort_order',
        'trial_days',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'trial_days' => 'integer',
        ];
    }
}
