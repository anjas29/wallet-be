<?php

namespace App\Providers;

use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound (rather than called directly) so tests can swap in a mock — see
        // App\Services\SubscriptionPlanService. Built directly with `new`, matching Cashier::
        // stripe()'s own config, rather than calling Cashier::stripe() itself — that method
        // resolves StripeClient::class through the container too, which would recurse into
        // this very binding.
        $this->app->bind(StripeClient::class, fn () => new StripeClient([
            'api_key' => config('cashier.secret'),
            'stripe_version' => Cashier::STRIPE_VERSION,
            'api_base' => Cashier::$apiBaseUrl,
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // ULID-keyed overrides of Cashier's models — see App\Models\Subscription/SubscriptionItem.
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);
    }
}
