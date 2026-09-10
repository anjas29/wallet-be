<?php

namespace Tests\Feature;

use App\Models\AccountDeleteRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountDeleteRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_renders_with_both_confirmation_controls(): void
    {
        $response = $this->get('/account-delete-request');

        $response->assertStatus(200);
        $response->assertSee('Request account deletion');
        $response->assertSee('name="email"', false);
        $response->assertSee('name="email_confirmation"', false);
        $response->assertSee('name="confirm"', false);
    }

    public function test_submission_records_a_pending_request_linked_to_the_account(): void
    {
        $user = User::factory()->create(['email' => 'leaver@example.com']);

        $response = $this->post('/account-delete-request', [
            'email' => 'leaver@example.com',
            'email_confirmation' => 'leaver@example.com',
            'confirm' => '1',
        ]);

        $response->assertRedirect('/account-delete-request/submitted');

        $this->assertDatabaseHas('account_delete_requests', [
            'email' => 'leaver@example.com',
            'user_id' => $user->id,
            'status' => AccountDeleteRequest::STATUS_PENDING,
        ]);

        // The request alone changes nothing about the account.
        $this->assertNull(User::find($user->id)->deleted_at);
    }

    public function test_submitted_page_confirms_the_address(): void
    {
        User::factory()->create(['email' => 'leaver@example.com']);

        $response = $this->post('/account-delete-request', [
            'email' => 'Leaver@Example.com',
            'email_confirmation' => 'Leaver@Example.com',
            'confirm' => '1',
        ])->assertRedirect();

        $this->get($response->headers->get('Location'))
            ->assertStatus(200)
            ->assertSee('leaver@example.com');
    }

    public function test_unknown_address_is_recorded_without_revealing_that_it_is_unknown(): void
    {
        $response = $this->post('/account-delete-request', [
            'email' => 'nobody@example.com',
            'email_confirmation' => 'nobody@example.com',
            'confirm' => '1',
        ]);

        $response->assertRedirect('/account-delete-request/submitted');
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('account_delete_requests', [
            'email' => 'nobody@example.com',
            'user_id' => null,
            'status' => AccountDeleteRequest::STATUS_PENDING,
        ]);
    }

    public function test_email_is_normalised_to_lower_case(): void
    {
        $user = User::factory()->create(['email' => 'mixed@example.com']);

        $this->post('/account-delete-request', [
            'email' => '  MiXeD@Example.COM ',
            'email_confirmation' => '  MiXeD@Example.COM ',
            'confirm' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('account_delete_requests', [
            'email' => 'mixed@example.com',
            'user_id' => $user->id,
        ]);
    }

    public function test_mismatched_confirmation_email_is_rejected(): void
    {
        $this->post('/account-delete-request', [
            'email' => 'a@example.com',
            'email_confirmation' => 'b@example.com',
            'confirm' => '1',
        ])->assertSessionHasErrors('email_confirmation');

        $this->assertDatabaseCount('account_delete_requests', 0);
    }

    public function test_unchecked_confirmation_is_rejected(): void
    {
        $this->post('/account-delete-request', [
            'email' => 'a@example.com',
            'email_confirmation' => 'a@example.com',
        ])->assertSessionHasErrors('confirm');

        $this->assertDatabaseCount('account_delete_requests', 0);
    }

    public function test_repeat_submission_refreshes_the_pending_request_instead_of_duplicating_it(): void
    {
        User::factory()->create(['email' => 'leaver@example.com']);

        $payload = [
            'email' => 'leaver@example.com',
            'email_confirmation' => 'leaver@example.com',
            'confirm' => '1',
        ];

        $this->post('/account-delete-request', $payload)->assertRedirect();
        $this->post('/account-delete-request', $payload)->assertRedirect();

        $this->assertDatabaseCount('account_delete_requests', 1);
    }

    public function test_a_new_request_is_opened_after_the_previous_one_was_processed(): void
    {
        $user = User::factory()->create(['email' => 'leaver@example.com']);
        $admin = User::factory()->admin()->create();

        DB::table('account_delete_requests')->insert([
            'id' => (string) Str::ulid(),
            'email' => 'leaver@example.com',
            'user_id' => $user->id,
            'status' => AccountDeleteRequest::STATUS_IGNORED,
            'processed_by' => $admin->id,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/account-delete-request', [
            'email' => 'leaver@example.com',
            'email_confirmation' => 'leaver@example.com',
            'confirm' => '1',
        ])->assertRedirect();

        $this->assertDatabaseCount('account_delete_requests', 2);
        $this->assertSame(1, AccountDeleteRequest::where('status', AccountDeleteRequest::STATUS_PENDING)->count());
    }
}
