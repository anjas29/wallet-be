<?php

namespace Tests\Feature\Api\V1;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'slug' => Str::random(8),
            'name' => 'Plan',
            'price_amount' => 499,
            'interval' => 'month',
            'is_active' => true,
            'stripe_price_id' => 'price_'.Str::random(8),
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_plan_listing_is_public_and_excludes_inactive_or_unpriced_plans(): void
    {
        $this->makePlan(['slug' => 'basic', 'sort_order' => 1]);
        $this->makePlan(['slug' => 'pro', 'sort_order' => 2]);
        $this->makePlan(['slug' => 'archived', 'is_active' => false]);
        $this->makePlan(['slug' => 'unsynced', 'stripe_price_id' => null]);

        $this->getJson('/api/v1/subscription-plans')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.slug', 'basic')
            ->assertJsonPath('data.items.1.slug', 'pro')
            ->assertJsonCount(2, 'data.items');
    }

    public function test_current_subscription_is_none_for_a_user_with_no_subscription(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/subscriptions/me')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.plan', null);
    }

    public function test_subscribing_to_an_unknown_plan_returns_404(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscriptions', ['plan_id' => (string) Str::ulid()])
            ->assertStatus(404);
    }

    public function test_subscribing_when_already_subscribed_is_rejected_without_calling_stripe(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $plan = $this->makePlan(['stripe_price_id' => 'price_basic']);

        DB::table('subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_existing',
            'stripe_status' => 'active',
            'stripe_price' => 'price_basic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_id');
    }

    public function test_cancelling_without_a_subscription_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/subscriptions/cancel')
            ->assertStatus(422);
    }

    public function test_plan_resource_exposes_trial_days(): void
    {
        $this->makePlan(['slug' => 'basic', 'trial_days' => 30]);

        $this->getJson('/api/v1/subscription-plans')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.trial_days', 30);
    }

    public function test_first_time_subscriber_is_eligible_for_the_plans_trial(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan(['trial_days' => 30]);

        $this->assertSame(30, app(SubscriptionService::class)->trialDaysFor($user, $plan));
    }

    public function test_a_plan_with_no_trial_configured_grants_none(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan(['trial_days' => null]);

        $this->assertNull(app(SubscriptionService::class)->trialDaysFor($user, $plan));
    }

    public function test_a_user_who_already_had_a_trial_does_not_get_a_second_one(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan(['trial_days' => 30]);

        // A previous "default" subscription that had a trial — cancelled and expired.
        DB::table('subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_previous',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_'.Str::random(8),
            'trial_ends_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
            'created_at' => now()->subDays(90),
            'updated_at' => now()->subDays(30),
        ]);

        $this->assertNull(app(SubscriptionService::class)->trialDaysFor($user, $plan));
    }
}
