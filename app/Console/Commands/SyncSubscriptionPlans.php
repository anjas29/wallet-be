<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use App\Services\SubscriptionPlanService;
use Illuminate\Console\Command;

class SyncSubscriptionPlans extends Command
{
    protected $signature = 'subscriptions:sync';

    protected $description = 'Create/update Stripe Products & Prices from config/subscriptions.php and upsert subscription_plans.';

    public function __construct(private SubscriptionPlanService $plans)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach (config('subscriptions.plans') as $definition) {
            $plan = SubscriptionPlan::where('slug', $definition['slug'])->first();

            $plan = $plan
                ? $this->plans->update($plan, $definition)
                : $this->plans->create($definition);

            $this->info("Synced plan '{$definition['slug']}' → price {$plan->stripe_price_id}");
        }

        return self::SUCCESS;
    }
}
