<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Services\SubscriptionService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Subscriptions', weight: 3)]
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    /**
     * List subscription plans
     *
     * Public — a pricing/paywall screen needs this before the user signs in.
     */
    public function plans()
    {
        return $this->collection(SubscriptionPlanResource::collection($this->subscriptions->activePlans()));
    }

    /**
     * Get current subscription
     *
     * Returns `{ "status": "none", "plan": null }` when the caller has no subscription
     * (treated as the free tier), rather than a 404.
     */
    public function show(Request $request)
    {
        ['subscription' => $subscription, 'plan' => $plan] = $this->subscriptions->current($request->user());

        return $this->success(new SubscriptionResource($subscription, $plan));
    }

    /**
     * Start a subscription
     *
     * Creates a Stripe Checkout session for the plan and returns `checkout_url` — open it in
     * a browser tab (e.g. Chrome Custom Tabs on Android). No client-side Stripe SDK needed.
     * The subscription only becomes `active` once the user completes payment on Stripe's
     * hosted page and Stripe's webhook reports back.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string'],
        ]);

        $plan = $this->subscriptions->findActivePlan($data['plan_id']);

        abort_if($plan === null, 404, 'Plan not found.');

        return $this->success($this->subscriptions->subscribe($request->user(), $plan), 'Checkout session created.', 201);
    }

    /**
     * Change plan
     *
     * Upgrades or downgrades the caller's active subscription; Stripe prorates the difference.
     */
    public function swap(Request $request)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string'],
        ]);

        $plan = $this->subscriptions->findActivePlan($data['plan_id']);

        abort_if($plan === null, 404, 'Plan not found.');

        $this->subscriptions->swap($request->user(), $plan);

        return $this->success(null, 'Plan changed.');
    }

    /**
     * Cancel subscription
     *
     * Cancels at the end of the current billing period; access continues through the grace
     * period (see `cancel_at_period_end` / `ends_at` on GET /subscriptions/me).
     */
    public function cancel(Request $request)
    {
        $this->subscriptions->cancel($request->user());

        return $this->success(null, 'Subscription will cancel at the end of the current period.');
    }

    /**
     * Resume subscription
     *
     * Undoes a pending cancellation, as long as the subscription is still within its grace period.
     */
    public function resume(Request $request)
    {
        $this->subscriptions->resume($request->user());

        return $this->success(null, 'Subscription resumed.');
    }
}
