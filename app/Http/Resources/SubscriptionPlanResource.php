<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price_amount' => $this->price_amount,
            'currency' => config('cashier.currency'),
            'interval' => $this->interval,
            'interval_count' => $this->interval_count,
            // Normalized per-month cost, always computable from this plan alone.
            'monthly_price' => $this->resource->monthlyPriceCents(),
            // Store-wide comparisons against the anchor plan — only present when this resource
            // came from SubscriptionService::activePlans(); absent (null/false) otherwise, e.g.
            // when showing a single already-subscribed plan on GET /subscriptions/me.
            'price_saved' => $this->whenNotNull($this->price_saved),
            'best_value' => (bool) $this->best_value,
            'trial_days' => $this->trial_days,
            'features' => $this->features,
        ];
    }
}
