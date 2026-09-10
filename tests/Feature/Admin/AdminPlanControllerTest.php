<?php

namespace Tests\Feature\Admin;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Stripe\StripeClient;
use Tests\TestCase;

class AdminPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'slug' => 'basic',
            'name' => 'Basic',
            'price_amount' => 499,
            'interval' => 'month',
            'stripe_price_id' => 'price_basic',
        ], $overrides));
    }

    /**
     * Binds a fake StripeClient (see AppServiceProvider) so admin actions never hit the real
     * Stripe API in tests, and returns its `products`/`prices` service mocks to set
     * expectations on.
     *
     * @return array{0: MockInterface, 1: MockInterface}
     */
    private function fakeStripe(): array
    {
        $stripe = Mockery::mock(StripeClient::class);
        $products = Mockery::mock();
        $prices = Mockery::mock();

        $stripe->shouldReceive('getService')->with('products')->andReturn($products);
        $stripe->shouldReceive('getService')->with('prices')->andReturn($prices);

        $this->app->instance(StripeClient::class, $stripe);

        return [$products, $prices];
    }

    /**
     * A fake Stripe\Price shaped object — matches what SubscriptionPlanService::resolvePrice()
     * reads (unit_amount/recurring->interval/interval_count/product).
     */
    private function stripePrice(string $productId, int $unitAmount, string $interval = 'month', int $intervalCount = 1, string $id = 'price_basic'): object
    {
        return (object) [
            'id' => $id,
            'product' => $productId,
            'unit_amount' => $unitAmount,
            'recurring' => (object) ['interval' => $interval, 'interval_count' => $intervalCount],
        ];
    }

    public function test_guest_is_redirected_from_the_plan_list(): void
    {
        $this->get('/admin/plans')->assertRedirect('/');
    }

    public function test_authenticated_non_admin_gets_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/plans')
            ->assertStatus(403);
    }

    public function test_admin_can_list_plans(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makePlan(['name' => 'Basic Plan']);

        $this->actingAs($admin)
            ->get('/admin/plans')
            ->assertStatus(200)
            ->assertSee('Basic Plan');
    }

    public function test_creating_a_plan_creates_a_stripe_product_and_price(): void
    {
        $admin = User::factory()->admin()->create();
        [$products, $prices] = $this->fakeStripe();

        $prices->shouldReceive('all')->once()
            ->with(['lookup_keys' => ['basic'], 'limit' => 1])
            ->andReturn((object) ['data' => []]);

        $products->shouldReceive('create')
            ->once()
            ->with(Mockery::on(fn ($attrs) => $attrs['name'] === 'Basic'))
            ->andReturn((object) ['id' => 'prod_basic']);

        $prices->shouldReceive('create')
            ->once()
            ->with(Mockery::on(fn ($attrs) => $attrs['product'] === 'prod_basic'
                && $attrs['unit_amount'] === 499
                && $attrs['lookup_key'] === 'basic'))
            ->andReturn((object) ['id' => 'price_basic']);

        $response = $this->actingAs($admin)->post('/admin/plans', [
            'slug' => 'basic',
            'name' => 'Basic',
            'description' => 'Core features',
            'price_amount' => 499,
            'interval' => 'month',
            'trial_days' => 30,
            'features' => "unlimited_accounts\nunlimited_transactions",
            'sort_order' => 1,
            'is_active' => '1',
        ]);

        $response->assertRedirect('/admin/plans');

        $this->assertDatabaseHas('subscription_plans', [
            'slug' => 'basic',
            'price_amount' => 499,
            'stripe_price_id' => 'price_basic',
            'trial_days' => 30,
            'is_active' => true,
        ]);

        $this->assertSame(
            ['unlimited_accounts', 'unlimited_transactions'],
            SubscriptionPlan::where('slug', 'basic')->first()->features,
        );
    }

    public function test_create_rejects_an_invalid_slug_without_calling_stripe(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/plans', [
            'slug' => 'Not Valid!',
            'name' => 'Basic',
            'price_amount' => 499,
            'interval' => 'month',
        ])->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('subscription_plans', 0);
    }

    public function test_create_rejects_a_duplicate_slug(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makePlan(['slug' => 'basic']);

        $this->actingAs($admin)->post('/admin/plans', [
            'slug' => 'basic',
            'name' => 'Basic Again',
            'price_amount' => 499,
            'interval' => 'month',
        ])->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('subscription_plans', 1);
    }

    public function test_updating_metadata_only_does_not_replace_the_stripe_price(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = $this->makePlan();
        [$products, $prices] = $this->fakeStripe();

        $prices->shouldReceive('retrieve')->once()->with('price_basic')->andReturn($this->stripePrice('prod_basic', 499));
        $products->shouldReceive('update')->once()->with('prod_basic', Mockery::any())->andReturn((object) ['id' => 'prod_basic']);
        $prices->shouldReceive('update')->once()
            ->with('price_basic', Mockery::on(fn ($attrs) => $attrs['active'] === true && $attrs['lookup_key'] === 'basic'))
            ->andReturnNull();
        $prices->shouldNotReceive('create');

        $response = $this->actingAs($admin)->post("/admin/plans/{$plan->id}", [
            'slug' => 'basic',
            'name' => 'Basic Updated',
            'price_amount' => 499,
            'interval' => 'month',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/admin/plans');
        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'name' => 'Basic Updated',
            'stripe_price_id' => 'price_basic',
        ]);
    }

    public function test_changing_the_price_creates_a_new_stripe_price_and_archives_the_old_one(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = $this->makePlan();
        [$products, $prices] = $this->fakeStripe();

        $prices->shouldReceive('retrieve')->once()->with('price_basic')->andReturn($this->stripePrice('prod_basic', 499));
        $products->shouldReceive('update')->once()->andReturn((object) ['id' => 'prod_basic']);
        $prices->shouldReceive('create')->once()
            ->with(Mockery::on(fn ($attrs) => $attrs['unit_amount'] === 999 && ($attrs['transfer_lookup_key'] ?? false) === true))
            ->andReturn((object) ['id' => 'price_new']);
        $prices->shouldReceive('update')->once()->with('price_basic', ['active' => false])->andReturnNull();

        $response = $this->actingAs($admin)->post("/admin/plans/{$plan->id}", [
            'slug' => 'basic',
            'name' => 'Basic',
            'price_amount' => 999,
            'interval' => 'month',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/admin/plans');
        $this->assertDatabaseHas('subscription_plans', [
            'id' => $plan->id,
            'price_amount' => 999,
            'stripe_price_id' => 'price_new',
        ]);
    }

    public function test_archiving_a_plan_deactivates_it_locally_and_on_stripe(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = $this->makePlan();
        [, $prices] = $this->fakeStripe();

        $prices->shouldReceive('update')->once()->with('price_basic', ['active' => false])->andReturnNull();

        $response = $this->actingAs($admin)->post("/admin/plans/{$plan->id}/archive");

        $response->assertRedirect('/admin/plans');
        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'is_active' => false]);
    }

    public function test_marking_a_plan_as_anchor_unmarks_every_other_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $existingAnchor = $this->makePlan(['slug' => 'basic', 'stripe_price_id' => 'price_basic', 'is_anchor' => true]);
        [$products, $prices] = $this->fakeStripe();

        $prices->shouldReceive('retrieve')->once()->with('price_basic')->andReturn($this->stripePrice('prod_basic', 499));
        $products->shouldReceive('update')->once()->andReturn((object) ['id' => 'prod_basic']);
        $prices->shouldReceive('update')->once()->andReturnNull();

        $response = $this->actingAs($admin)->post("/admin/plans/{$existingAnchor->id}", [
            'slug' => 'basic',
            'name' => 'Basic',
            'price_amount' => 499,
            'interval' => 'month',
            'is_active' => '1',
            'is_anchor' => '1',
        ]);

        $response->assertRedirect('/admin/plans');
        $this->assertDatabaseHas('subscription_plans', ['id' => $existingAnchor->id, 'is_anchor' => true]);

        // Now create a second plan as the anchor — the first should lose the flag.
        [$products, $prices] = $this->fakeStripe();

        $prices->shouldReceive('all')->once()->andReturn((object) ['data' => []]);
        $products->shouldReceive('create')->once()->andReturn((object) ['id' => 'prod_pro']);
        $prices->shouldReceive('create')->once()->andReturn((object) ['id' => 'price_pro']);

        $this->actingAs($admin)->post('/admin/plans', [
            'slug' => 'pro',
            'name' => 'Pro',
            'price_amount' => 999,
            'interval' => 'month',
            'is_active' => '1',
            'is_anchor' => '1',
        ])->assertRedirect('/admin/plans');

        $this->assertDatabaseHas('subscription_plans', ['slug' => 'pro', 'is_anchor' => true]);
        $this->assertDatabaseHas('subscription_plans', ['id' => $existingAnchor->id, 'is_anchor' => false]);
    }

    /**
     * Guards the exact bug this was written for: a plan's local row doesn't exist yet (e.g. a
     * fresh database sharing the same Stripe account as another environment), but a Stripe Price
     * under this slug's lookup key already does. Creating must reuse it, not collide on it.
     */
    public function test_creating_a_plan_reuses_a_matching_stripe_price_found_by_lookup_key(): void
    {
        $admin = User::factory()->admin()->create();
        [$products, $prices] = $this->fakeStripe();

        $prices->shouldReceive('all')->once()
            ->with(['lookup_keys' => ['basic'], 'limit' => 1])
            ->andReturn((object) ['data' => [$this->stripePrice('prod_existing', 499, id: 'price_existing')]]);

        $products->shouldReceive('update')->once()->with('prod_existing', Mockery::any())->andReturn((object) ['id' => 'prod_existing']);
        $products->shouldNotReceive('create');
        $prices->shouldNotReceive('create');

        $response = $this->actingAs($admin)->post('/admin/plans', [
            'slug' => 'basic',
            'name' => 'Basic',
            'price_amount' => 499,
            'interval' => 'month',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/admin/plans');
        $this->assertDatabaseHas('subscription_plans', ['slug' => 'basic', 'stripe_price_id' => 'price_existing']);
    }

    public function test_creating_a_plan_replaces_a_mismatched_stripe_price_found_by_lookup_key(): void
    {
        $admin = User::factory()->admin()->create();
        [$products, $prices] = $this->fakeStripe();

        $prices->shouldReceive('all')->once()
            ->andReturn((object) ['data' => [$this->stripePrice('prod_existing', 399, id: 'price_old')]]);

        $products->shouldReceive('update')->once()->andReturn((object) ['id' => 'prod_existing']);
        $products->shouldNotReceive('create');
        $prices->shouldReceive('create')->once()
            ->with(Mockery::on(fn ($attrs) => $attrs['unit_amount'] === 499 && ($attrs['transfer_lookup_key'] ?? false) === true))
            ->andReturn((object) ['id' => 'price_new']);
        $prices->shouldReceive('update')->once()->with('price_old', ['active' => false])->andReturnNull();

        $response = $this->actingAs($admin)->post('/admin/plans', [
            'slug' => 'basic',
            'name' => 'Basic',
            'price_amount' => 499,
            'interval' => 'month',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/admin/plans');
        $this->assertDatabaseHas('subscription_plans', ['slug' => 'basic', 'stripe_price_id' => 'price_new']);
    }
}
