<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function activePlans(): Collection
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->whereNotNull('stripe_price_id')
            ->orderBy('sort_order')
            ->get();

        return $this->attachPricingMeta($plans);
    }

    /**
     * Attaches `price_saved` and `best_value` — computed store-wide against whichever plan is
     * flagged `is_anchor`, normalized to a monthly rate so plans on different billing intervals
     * (monthly/quarterly/yearly) are comparable. Not persisted; read by SubscriptionPlanResource.
     */
    private function attachPricingMeta(Collection $plans): Collection
    {
        if ($plans->isEmpty()) {
            return $plans;
        }

        $anchor = $plans->firstWhere('is_anchor', true);
        $anchorMonthlyPrice = $anchor?->monthlyPriceCents();
        $cheapestMonthlyPrice = $plans->min(fn (SubscriptionPlan $plan) => $plan->monthlyPriceCents());

        foreach ($plans as $plan) {
            $monthlyPrice = $plan->monthlyPriceCents();

            $plan->setAttribute(
                'price_saved',
                $anchorMonthlyPrice !== null ? ($anchorMonthlyPrice * $plan->totalMonths()) - $plan->price_amount : null,
            );
            $plan->setAttribute('best_value', $monthlyPrice === $cheapestMonthlyPrice);
        }

        return $plans;
    }

    public function findActivePlan(string $id): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('id', $id)
            ->where('is_active', true)
            ->whereNotNull('stripe_price_id')
            ->first();
    }

    /**
     * @return array{subscription: Subscription|null, plan: SubscriptionPlan|null}
     */
    public function current(User $user): array
    {
        $subscription = $user->subscription('default');

        $plan = $subscription
            ? SubscriptionPlan::where('stripe_price_id', $subscription->stripe_price)->first()
            : null;

        return ['subscription' => $subscription, 'plan' => $plan];
    }

    /**
     * Start a subscription for a plan the user does not already hold, returning a Stripe
     * Checkout URL for the Android app to open in a browser tab (Chrome Custom Tabs).
     *
     * Uses Cashier's `checkout()` helper (Stripe-hosted payment page) rather than a native
     * SDK flow — no client-side Stripe integration needed on Android. The subscription itself
     * doesn't exist locally until the user completes payment and Stripe's webhook fires
     * `customer.subscription.created`, which Cashier's webhook handler reconciles into the
     * local `subscriptions`/`subscription_items` rows automatically — trial_ends_at included.
     *
     * @return array{checkout_url: string, session_id: string}
     */
    public function subscribe(User $user, SubscriptionPlan $plan): array
    {
        if ($user->subscribed('default')) {
            throw ValidationException::withMessages([
                'plan_id' => ['You already have an active subscription.'],
            ]);
        }

        $sessionOptions = [
            'mode' => 'subscription',
            'success_url' => config('subscriptions.checkout.success_url'),
            'cancel_url' => config('subscriptions.checkout.cancel_url'),
        ];

        if ($trialDays = $this->trialDaysFor($user, $plan)) {
            $sessionOptions['subscription_data']['trial_period_days'] = $trialDays;
        }

        $checkout = $user->checkout([$plan->stripe_price_id], $sessionOptions);

        return [
            'checkout_url' => $checkout->url,
            'session_id' => $checkout->id,
        ];
    }

    /**
     * A plan's trial only applies to a user's first-ever "default" subscription — otherwise
     * cancelling and resubscribing would grant a fresh free trial every time.
     */
    public function trialDaysFor(User $user, SubscriptionPlan $plan): ?int
    {
        if ($plan->trial_days === null) {
            return null;
        }

        $hadTrialBefore = $user->subscriptions()
            ->where('type', 'default')
            ->whereNotNull('trial_ends_at')
            ->exists();

        return $hadTrialBefore ? null : $plan->trial_days;
    }

    /**
     * Change tier on an existing subscription. Cashier handles proration.
     */
    public function swap(User $user, SubscriptionPlan $plan): void
    {
        $subscription = $user->subscription('default');

        if ($subscription === null) {
            throw ValidationException::withMessages([
                'plan_id' => ['You do not have an active subscription to change.'],
            ]);
        }

        $subscription->swap($plan->stripe_price_id);
    }

    /**
     * Cancel at period end — the subscription stays valid through its current grace period.
     */
    public function cancel(User $user): void
    {
        $subscription = $user->subscription('default');

        if ($subscription === null || $subscription->canceled()) {
            throw ValidationException::withMessages([
                'subscription' => ['You do not have an active subscription to cancel.'],
            ]);
        }

        $subscription->cancel();
    }

    /**
     * Undo a pending cancellation still within its grace period.
     */
    public function resume(User $user): void
    {
        $subscription = $user->subscription('default');

        if ($subscription === null || ! $subscription->onGracePeriod()) {
            throw ValidationException::withMessages([
                'subscription' => ['You do not have a cancelled subscription to resume.'],
            ]);
        }

        $subscription->resume();
    }
}
