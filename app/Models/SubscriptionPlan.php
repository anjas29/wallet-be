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
        'interval_count',
        'features',
        'stripe_price_id',
        'is_active',
        'is_anchor',
        'sort_order',
        'trial_days',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'interval_count' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_anchor' => 'boolean',
            'sort_order' => 'integer',
            'trial_days' => 'integer',
        ];
    }

    /**
     * This plan's billing cycle length in months (e.g. month/3 = quarterly = 3, year/1 = 12).
     */
    public function totalMonths(): int
    {
        return ($this->interval === 'year' ? 12 : 1) * $this->interval_count;
    }

    /**
     * Price normalized to a per-month cost, for comparing plans billed at different intervals.
     */
    public function monthlyPriceCents(): int
    {
        return (int) round($this->price_amount / $this->totalMonths());
    }
}
