<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | The single source of truth for this app's subscription tiers. Run
    | `php artisan subscriptions:sync` after editing this list to create/update the
    | corresponding Stripe Products + Prices and upsert `subscription_plans` rows with
    | the resulting Stripe Price ID — nothing needs to be clicked in the Stripe Dashboard.
    |
    | `price_amount` is in the smallest currency unit (cents for USD) and only used to
    | create the Stripe Price and for local display; Stripe's Price object stays the
    | source of truth for what a customer is actually charged.
    |
    | `trial_days` is not a Stripe Price attribute (trials live on the subscription, not
    | the Price) — it's applied at subscribe time via Checkout's `subscription_data.
    | trial_period_days`, and only for a user's first subscription (see
    | SubscriptionService::trialDaysFor()) so cancel-and-resubscribe can't repeat it.
    |
    */

    'plans' => [
        [
            'slug' => 'basic',
            'name' => 'Basic',
            'description' => 'Core budgeting features for a single user.',
            'price_amount' => 499,
            'interval' => 'month',
            'trial_days' => 30,
            'features' => [
                'unlimited_accounts',
                'unlimited_transactions',
            ],
            'sort_order' => 1,
        ],
        [
            'slug' => 'pro',
            'name' => 'Pro',
            'description' => 'Basic, plus AI-powered insights and receipt scanning.',
            'price_amount' => 999,
            'interval' => 'month',
            'trial_days' => 30,
            'features' => [
                'unlimited_accounts',
                'unlimited_transactions',
                'ai_chat',
                'receipt_scanning',
            ],
            'sort_order' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout Redirects
    |--------------------------------------------------------------------------
    |
    | Where Stripe's hosted Checkout page sends the browser after payment. These should be
    | Android App Links (or a custom URI scheme) the app intercepts to close the browser tab
    | and return to the app — coordinate the exact values with the Android team. The
    | `{CHECKOUT_SESSION_ID}` placeholder is replaced by Stripe with the actual session id.
    |
    */

    'checkout' => [
        'success_url' => env('STRIPE_CHECKOUT_SUCCESS_URL', env('APP_URL', 'http://localhost').'/subscriptions/checkout/success?session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => env('STRIPE_CHECKOUT_CANCEL_URL', env('APP_URL', 'http://localhost').'/subscriptions/checkout/cancelled'),
    ],

];
