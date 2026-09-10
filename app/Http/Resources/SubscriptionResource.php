<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a nullable Cashier `Subscription` alongside its resolved `SubscriptionPlan`. A user
 * with no subscription still gets a 200 with `status: "none"`, treated as the free tier.
 */
class SubscriptionResource extends JsonResource
{
    public function __construct(private $subscription, private $plan)
    {
        parent::__construct($subscription);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->subscription === null) {
            return ['status' => 'none', 'plan' => null];
        }

        return [
            'status' => $this->subscription->stripe_status,
            'plan' => $this->plan ? new SubscriptionPlanResource($this->plan) : null,
            // A pending cancellation still runs until `ends_at`; onGracePeriod() is a local
            // check (no Stripe API call) against that column.
            'cancel_at_period_end' => $this->subscription->onGracePeriod(),
            'trial_ends_at' => $this->subscription->trial_ends_at?->toIso8601String(),
            'ends_at' => $this->subscription->ends_at?->toIso8601String(),
        ];
    }
}
