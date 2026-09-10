<?php

namespace Tests\Feature\Admin;

use App\Models\AccountDeleteRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAccountDeleteRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user with one row in every table the purge is expected to touch.
     *
     * @return array<string, string>
     */
    private function seedUserData(User $user): array
    {
        $ids = [
            'currency' => (string) Str::ulid(),
            'userCurrency' => (string) Str::ulid(),
            'account' => (string) Str::ulid(),
            'otherAccount' => (string) Str::ulid(),
            'category' => (string) Str::ulid(),
            'transaction' => (string) Str::ulid(),
            'attachment' => (string) Str::ulid(),
            'transfer' => (string) Str::ulid(),
            'liability' => (string) Str::ulid(),
            'liabilityPayment' => (string) Str::ulid(),
            'budget' => (string) Str::ulid(),
            'recurring' => (string) Str::ulid(),
            'conversation' => (string) Str::ulid(),
            'message' => (string) Str::ulid(),
        ];

        $now = now();

        // Currencies are global, not per-user: reuse the row when seeding a second user.
        $existingCurrency = DB::table('currencies')->where('code', 'USD')->value('id');

        if ($existingCurrency) {
            $ids['currency'] = $existingCurrency;
        } else {
            DB::table('currencies')->insert([
                'id' => $ids['currency'], 'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$',
                'decimal_places' => 2, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('user_currencies')->insert([
            'id' => $ids['userCurrency'], 'user_id' => $user->id, 'currency_id' => $ids['currency'],
            'exchange_rate' => 1, 'is_anchor' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (['account' => 'Checking', 'otherAccount' => 'Savings'] as $key => $name) {
            DB::table('accounts')->insert([
                'id' => $ids[$key], 'user_id' => $user->id, 'user_currency_id' => $ids['userCurrency'],
                'name' => $name, 'type' => 'bank_account', 'initial_balance' => '0',
                'is_default' => $key === 'account', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('user_categories')->insert([
            'id' => $ids['category'], 'user_id' => $user->id, 'name' => 'Groceries',
            'type' => 'expense', 'icon' => 'basket', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('transactions')->insert([
            'id' => $ids['transaction'], 'user_id' => $user->id, 'account_id' => $ids['account'],
            'category_id' => $ids['category'], 'type' => 'expense', 'amount' => '10.00',
            'transaction_date' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('transaction_attachments')->insert([
            'id' => $ids['attachment'], 'transaction_id' => $ids['transaction'],
            'disk' => 'local', 'file_path' => 'receipts/x.jpg', 'file_name' => 'receipt.jpg',
            'mime_type' => 'image/jpeg', 'file_size' => 1024,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('transfers')->insert([
            'id' => $ids['transfer'], 'user_id' => $user->id,
            'from_account_id' => $ids['account'], 'to_account_id' => $ids['otherAccount'],
            'from_amount' => '5.00', 'to_amount' => '5.00', 'exchange_rate' => 1,
            'transfer_date' => $now->toDateString(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('liabilities')->insert([
            'id' => $ids['liability'], 'user_id' => $user->id, 'user_currency_id' => $ids['userCurrency'],
            'name' => 'Card', 'type' => 'credit_card', 'principal_amount' => '100.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('liability_payments')->insert([
            'id' => $ids['liabilityPayment'], 'liability_id' => $ids['liability'],
            'account_id' => $ids['account'], 'amount' => '20.00',
            'payment_date' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('budgets')->insert([
            'id' => $ids['budget'], 'user_id' => $user->id, 'category_id' => $ids['category'],
            // chk_budgets_period_shape: a monthly budget must carry neither date.
            'amount' => '500.00', 'period_type' => 'monthly',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('recurring_transactions')->insert([
            'id' => $ids['recurring'], 'user_id' => $user->id, 'account_id' => $ids['account'],
            'category_id' => $ids['category'], 'amount' => '9.99',
            'frequency' => 'monthly', 'start_date' => $now->toDateString(),
            'next_run_date' => $now->toDateString(), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('ai_conversations')->insert([
            'id' => $ids['conversation'], 'user_id' => $user->id, 'title' => 'Spending',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('ai_messages')->insert([
            'id' => $ids['message'], 'conversation_id' => $ids['conversation'], 'user_id' => $user->id,
            'role' => 'user', 'content' => 'How much did I spend?',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('refresh_tokens')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id, 'family_id' => (string) Str::ulid(),
            'token_hash' => hash('sha256', 'refresh-'.$user->id), 'device_id' => 'device-1',
            'expires_at' => $now->copy()->addDays(30), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('device_syncs')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $user->id, 'device_id' => 'device-1',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $ids;
    }

    private function pendingRequest(?User $user, string $email): AccountDeleteRequest
    {
        return AccountDeleteRequest::create([
            'email' => $email,
            'user_id' => $user?->id,
            'status' => AccountDeleteRequest::STATUS_PENDING,
        ]);
    }

    public function test_root_serves_the_admin_login_form(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Admin sign in');
        $response->assertSee('name="password"', false);
    }

    public function test_guest_is_redirected_from_the_panel_to_login(): void
    {
        $this->get('/admin/account-delete-requests')->assertRedirect('/');
    }

    public function test_admin_can_sign_in_and_lands_on_the_admin_home(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ops@example.com']);

        $response = $this->post('/', ['email' => 'ops@example.com', 'password' => 'password']);

        $response->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_non_admin_credentials_are_rejected_and_leave_no_session(): void
    {
        User::factory()->create(['email' => 'plain@example.com']);

        $this->post('/', ['email' => 'plain@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->admin()->create(['email' => 'ops@example.com']);

        $this->post('/', ['email' => 'ops@example.com', 'password' => 'nope'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_soft_deleted_user_cannot_sign_in(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ops@example.com']);
        $admin->delete();

        $this->post('/', ['email' => 'ops@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_non_admin_gets_403_from_the_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/account-delete-requests')
            ->assertStatus(403);
    }

    public function test_list_shows_pending_requests_by_default(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'leaver@example.com', 'name' => 'Lea Ver']);
        $this->pendingRequest($user, 'leaver@example.com');

        $ignored = $this->pendingRequest(null, 'ignored@example.com');
        $ignored->update(['status' => AccountDeleteRequest::STATUS_IGNORED]);

        $response = $this->actingAs($admin)->get('/admin/account-delete-requests');

        $response->assertStatus(200);
        $response->assertSee('leaver@example.com');
        $response->assertSee('Lea Ver');
        $response->assertDontSee('ignored@example.com');

        $this->actingAs($admin)
            ->get('/admin/account-delete-requests?status=ignored')
            ->assertSee('ignored@example.com')
            ->assertDontSee('leaver@example.com');
    }

    public function test_list_flags_a_request_with_no_matching_account(): void
    {
        $admin = User::factory()->admin()->create();
        $this->pendingRequest(null, 'nobody@example.com');

        $this->actingAs($admin)
            ->get('/admin/account-delete-requests')
            ->assertSee('No matching account');
    }

    public function test_ignore_closes_the_request_without_touching_the_account(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'leaver@example.com']);
        $request = $this->pendingRequest($user, 'leaver@example.com');

        $this->actingAs($admin)
            ->post("/admin/account-delete-requests/{$request->id}/ignore")
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(AccountDeleteRequest::STATUS_IGNORED, $request->status);
        $this->assertSame($admin->id, $request->processed_by);
        $this->assertNotNull($request->processed_at);
        $this->assertNotNull(User::find($user->id));
    }

    public function test_delete_soft_deletes_the_user_and_every_related_record(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'leaver@example.com']);
        $ids = $this->seedUserData($user);
        $user->createToken('phone');

        $request = $this->pendingRequest($user, 'leaver@example.com');

        $this->actingAs($admin)
            ->post("/admin/account-delete-requests/{$request->id}/delete")
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(AccountDeleteRequest::STATUS_DELETED, $request->status);
        $this->assertSame($admin->id, $request->processed_by);

        // The user, and everything they owned, is trashed — not gone.
        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id)->deleted_at);

        $trashed = [
            'accounts' => [$ids['account'], $ids['otherAccount']],
            'user_categories' => [$ids['category']],
            'transactions' => [$ids['transaction']],
            'transaction_attachments' => [$ids['attachment']],
            'transfers' => [$ids['transfer']],
            'liabilities' => [$ids['liability']],
            'liability_payments' => [$ids['liabilityPayment']],
            'budgets' => [$ids['budget']],
            'recurring_transactions' => [$ids['recurring']],
            'ai_conversations' => [$ids['conversation']],
            'ai_messages' => [$ids['message']],
        ];

        foreach ($trashed as $table => $rowIds) {
            foreach ($rowIds as $id) {
                $row = DB::table($table)->where('id', $id)->first();
                $this->assertNotNull($row, "{$table} row {$id} should still exist");
                $this->assertNotNull($row->deleted_at, "{$table} row {$id} should be soft-deleted");
            }
        }

        // Credentials and device state are revoked outright.
        $this->assertDatabaseCount('refresh_tokens', 0);
        $this->assertDatabaseCount('device_syncs', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_delete_does_not_touch_another_users_data(): void
    {
        $admin = User::factory()->admin()->create();
        $leaver = User::factory()->create(['email' => 'leaver@example.com']);
        $bystander = User::factory()->create(['email' => 'stays@example.com']);

        $this->seedUserData($leaver);
        $bystanderIds = $this->seedUserData($bystander);

        $request = $this->pendingRequest($leaver, 'leaver@example.com');

        $this->actingAs($admin)
            ->post("/admin/account-delete-requests/{$request->id}/delete")
            ->assertRedirect();

        $this->assertNotNull(User::find($bystander->id));
        $this->assertNull(DB::table('accounts')->where('id', $bystanderIds['account'])->first()->deleted_at);
        $this->assertNull(DB::table('transactions')->where('id', $bystanderIds['transaction'])->first()->deleted_at);
        $this->assertNull(DB::table('liability_payments')->where('id', $bystanderIds['liabilityPayment'])->first()->deleted_at);
        $this->assertSame(1, DB::table('refresh_tokens')->where('user_id', $bystander->id)->count());
    }

    public function test_delete_closes_a_request_that_matched_no_account(): void
    {
        $admin = User::factory()->admin()->create();
        $request = $this->pendingRequest(null, 'nobody@example.com');

        $this->actingAs($admin)
            ->post("/admin/account-delete-requests/{$request->id}/delete")
            ->assertRedirect();

        $this->assertSame(AccountDeleteRequest::STATUS_DELETED, $request->refresh()->status);
    }

    public function test_an_already_processed_request_cannot_be_processed_again(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'leaver@example.com']);
        $request = $this->pendingRequest($user, 'leaver@example.com');
        $request->update(['status' => AccountDeleteRequest::STATUS_IGNORED]);

        $this->actingAs($admin)
            ->post("/admin/account-delete-requests/{$request->id}/delete")
            ->assertStatus(409);

        $this->actingAs($admin)
            ->post("/admin/account-delete-requests/{$request->id}/ignore")
            ->assertStatus(409);

        $this->assertNotNull(User::find($user->id));
    }

    public function test_admin_cannot_delete_the_account_they_are_signed_in_with(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ops@example.com']);
        $request = $this->pendingRequest($admin, 'ops@example.com');

        $this->actingAs($admin)
            ->post("/admin/account-delete-requests/{$request->id}/delete")
            ->assertStatus(403);

        $this->assertNotNull(User::find($admin->id));
        $this->assertTrue($request->refresh()->isPending());
    }

    public function test_api_token_works_before_deletion(): void
    {
        $token = User::factory()->create()->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(200);
    }

    /**
     * Deliberately makes only ONE request: RequestGuard memoises its user for the lifetime of
     * the application instance, so a before/after pair inside a single test would see the
     * cached user rather than re-resolving the revoked token. Deletion is driven through the
     * service for the same reason `actingAs()` is avoided — it would seat the admin on the web
     * guard, which Sanctum consults before the bearer token.
     */
    public function test_deleted_user_loses_api_access(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'leaver@example.com']);
        $token = $user->createToken('phone')->plainTextToken;

        app(AccountDeletionService::class)->approve(
            $this->pendingRequest($user, 'leaver@example.com'),
            $admin,
        );

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(401);
    }

    public function test_purge_preserves_a_timestamp_the_user_had_already_set(): void
    {
        $user = User::factory()->create();
        $ids = $this->seedUserData($user);

        $earlier = now()->subDays(3);
        DB::table('accounts')->where('id', $ids['otherAccount'])->update(['deleted_at' => $earlier]);

        app(AccountDeletionService::class)->deleteUserData($user);

        $row = DB::table('accounts')->where('id', $ids['otherAccount'])->first();
        $this->assertSame($earlier->toDateTimeString(), Carbon::parse($row->deleted_at)->toDateTimeString());
    }

    public function test_logout_ends_the_admin_session(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }
}
