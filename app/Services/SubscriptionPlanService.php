<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use Stripe\StripeClient;

/**
 * Creates, updates and archives subscription plans — keeping each plan's Stripe Product/Price
 * in sync with the local `subscription_plans` row. Used by both the admin CRUD screens and
 * `php artisan subscriptions:sync` (which drives this from config/subscriptions.php), so plan
 * changes only ever go through one place that talks to Stripe.
 */
class SubscriptionPlanService
{
    public function __construct(private StripeClient $stripe) {}

    /**
     * @param  array{slug: string, name: string, description?: ?string, price_amount: int, interval: string, interval_count?: int, features?: array, is_active?: bool, is_anchor?: bool, sort_order?: int, trial_days?: ?int}  $data
     */
    public function create(array $data): SubscriptionPlan
    {
        $product = $this->stripe->products->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $price = $this->stripe->prices->create([
            'product' => $product->id,
            'unit_amount' => $data['price_amount'],
            'currency' => config('cashier.currency'),
            'recurring' => ['interval' => $data['interval'], 'interval_count' => $data['interval_count'] ?? 1],
            'lookup_key' => $data['slug'],
        ]);

        $plan = SubscriptionPlan::create([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_amount' => $data['price_amount'],
            'interval' => $data['interval'],
            'interval_count' => $data['interval_count'] ?? 1,
            'features' => $data['features'] ?? [],
            'stripe_price_id' => $price->id,
            'is_active' => $data['is_active'] ?? true,
            'is_anchor' => $data['is_anchor'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
            'trial_days' => $data['trial_days'] ?? null,
        ]);

        $this->ensureSingleAnchor($plan);

        return $plan;
    }

    /**
     * A price/interval change creates a new immutable Stripe Price (`transfer_lookup_key`
     * moves the plan's lookup key onto it automatically) and archives the old one; every other
     * field, including `is_active`, updates the existing Product/Price in place.
     *
     * @param  array{slug: string, name: string, description?: ?string, price_amount: int, interval: string, interval_count?: int, features?: array, is_active?: bool, is_anchor?: bool, sort_order?: int, trial_days?: ?int}  $data
     */
    public function update(SubscriptionPlan $plan, array $data): SubscriptionPlan
    {
        $productId = $this->stripe->prices->retrieve($plan->stripe_price_id)->product;

        $this->stripe->products->update($productId, [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $intervalCount = $data['interval_count'] ?? 1;

        $priceChanged = (int) $data['price_amount'] !== (int) $plan->price_amount
            || $data['interval'] !== $plan->interval
            || $intervalCount !== $plan->interval_count;

        if ($priceChanged) {
            $newPrice = $this->stripe->prices->create([
                'product' => $productId,
                'unit_amount' => $data['price_amount'],
                'currency' => config('cashier.currency'),
                'recurring' => ['interval' => $data['interval'], 'interval_count' => $intervalCount],
                'lookup_key' => $data['slug'],
                'transfer_lookup_key' => true,
            ]);

            $this->stripe->prices->update($plan->stripe_price_id, ['active' => false]);

            $stripePriceId = $newPrice->id;
        } else {
            $stripePriceId = $plan->stripe_price_id;

            $this->stripe->prices->update($stripePriceId, [
                'active' => (bool) ($data['is_active'] ?? true),
                'lookup_key' => $data['slug'],
            ]);
        }

        $plan->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_amount' => $data['price_amount'],
            'interval' => $data['interval'],
            'interval_count' => $intervalCount,
            'features' => $data['features'] ?? [],
            'stripe_price_id' => $stripePriceId,
            'is_active' => $data['is_active'] ?? true,
            'is_anchor' => $data['is_anchor'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
            'trial_days' => $data['trial_days'] ?? null,
        ]);

        $this->ensureSingleAnchor($plan);

        return $plan;
    }

    /**
     * Only one plan can be the anchor at a time — same convention as `user_currencies.is_anchor`.
     */
    private function ensureSingleAnchor(SubscriptionPlan $plan): void
    {
        if ($plan->is_anchor) {
            SubscriptionPlan::where('id', '!=', $plan->id)->update(['is_anchor' => false]);
        }
    }

    /**
     * Deactivate a plan locally and archive its Stripe Price, so it can no longer be picked
     * for a new subscription. Existing subscribers are untouched — this never deletes the row
     * (an active Stripe Price can be reactivated later; the local plan is just a display gate).
     */
    public function archive(SubscriptionPlan $plan): void
    {
        $this->stripe->prices->update($plan->stripe_price_id, ['active' => false]);

        $plan->update(['is_active' => false]);
    }
}
