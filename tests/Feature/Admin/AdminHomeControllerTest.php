<?php

namespace Tests\Feature\Admin;

use App\Models\AccountDeleteRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHomeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_the_admin_home(): void
    {
        $this->get('/admin')->assertRedirect('/');
    }

    public function test_authenticated_non_admin_gets_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertStatus(403);
    }

    public function test_home_shows_counts_and_links_to_every_section(): void
    {
        $admin = User::factory()->admin()->create();

        AccountDeleteRequest::create(['email' => 'pending@example.com', 'status' => AccountDeleteRequest::STATUS_PENDING]);
        SubscriptionPlan::create([
            'slug' => 'basic', 'name' => 'Basic', 'price_amount' => 499, 'interval' => 'month',
            'stripe_price_id' => 'price_basic', 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
        $response->assertSee('1 pending request');
        $response->assertSee('1 active plan');
        $response->assertSee('0 active subscribers');
        $response->assertSee(route('admin.account-delete-requests.index'), false);
        $response->assertSee(route('admin.subscriptions.index'), false);
        $response->assertSee(route('admin.plans.index'), false);
    }

    public function test_already_logged_in_admin_visiting_root_is_sent_to_admin_home(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertRedirect('/admin');
    }
}
