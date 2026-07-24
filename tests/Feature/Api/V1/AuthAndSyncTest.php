<?php

namespace Tests\Feature\Api\V1;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthAndSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_and_access_profile(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status_code', 201)
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.role', 'user');

        // Timestamps are serialized as ISO-8601.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $response->json('data.user.created_at'),
        );

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        // Register also issues a refresh token (kept alongside the access token).
        $this->assertNotEmpty($response->json('data.refresh_token'));
        $this->assertNotEmpty($response->json('data.refresh_expires_at'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ada@example.com');
    }

    public function test_can_refresh_access_token_and_rotates_the_refresh_token(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ref',
            'email' => 'ref@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $oldRefresh = $register->json('data.refresh_token');

        $refresh = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ref@example.com');

        $newRefresh = $refresh->json('data.refresh_token');
        $newAccess = $refresh->json('data.token');

        // Rotated: a new refresh token is issued, and the new access token works.
        $this->assertNotEmpty($newRefresh);
        $this->assertNotSame($oldRefresh, $newRefresh);

        $this->withHeader('Authorization', 'Bearer '.$newAccess)
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(200);
    }

    public function test_invalid_and_expired_refresh_tokens_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'does-not-exist'])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid refresh token.');

        $user = User::factory()->create();
        $plain = Str::random(64);
        RefreshToken::create([
            'user_id' => $user->id,
            'family_id' => (string) Str::ulid(),
            'token_hash' => hash('sha256', $plain),
            'device_id' => 'default',
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $plain])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Refresh token has expired.');
    }

    public function test_reusing_a_rotated_refresh_token_revokes_the_family(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Reuse',
            'email' => 'reuse@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $original = $register->json('data.refresh_token');

        // First use rotates successfully.
        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $original])
            ->assertStatus(200)
            ->json('data.refresh_token');

        // Replaying the original (now revoked) token is rejected as reuse.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $original])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Refresh token has been revoked.');

        // ...and the whole family is revoked, so the rotated token no longer works either.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $rotated])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Refresh token has been revoked.');
    }

    public function test_invalid_credentials_return_error_envelope(): void
    {
        User::factory()->create(['email' => 'bob@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bob@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 401)
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_validation_failure_returns_error_envelope(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 422)
            ->assertJsonStructure(['success', 'status_code', 'message', 'errors' => ['name', 'email', 'password']]);
    }

    public function test_unauthenticated_request_returns_error_envelope(): void
    {
        $this->getJson('/api/v1/accounts')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_missing_record_returns_404_envelope(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/accounts/'.Str::ulid())
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 404)
            ->assertJsonPath('message', 'Resource not found.');
    }

    public function test_can_create_user_currency_and_account_via_rest(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $currencyId = (string) Str::ulid();
        DB::table('currencies')->insert([
            'id' => $currencyId,
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create the user currency
        $ucResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/user-currencies', [
                'currency_id' => $currencyId,
                'exchange_rate' => '1',
                'is_anchor' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status_code', 201)
            ->assertJsonPath('data.currency_id', $currencyId)
            ->assertJsonPath('data.is_anchor', true);

        $userCurrencyId = $ucResponse->json('data.id');
        $this->assertDatabaseHas('user_currencies', ['id' => $userCurrencyId, 'user_id' => $user->id]);

        // Duplicate currency for the same user is rejected
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/user-currencies', ['currency_id' => $currencyId])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // Create an account referencing that currency
        $accountResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/accounts', [
                'user_currency_id' => $userCurrencyId,
                'name' => 'Checking',
                'type' => 'bank_account',
                'initial_balance' => '50.00',
                'is_default' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Checking')
            ->assertJsonPath('data.balance', '50.00')
            ->assertJsonPath('data.color', '#64748B'); // DB default when not supplied

        $this->assertDatabaseHas('accounts', [
            'id' => $accountResponse->json('data.id'),
            'user_id' => $user->id,
            'is_default' => true,
        ]);
    }

    public function test_create_account_rejects_currency_not_owned_by_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/accounts', [
                'user_currency_id' => (string) Str::ulid(),
                'name' => 'Ghost',
                'type' => 'cash',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['data.user_currency_id']]);
    }

    public function test_sync_push_can_create_account_and_transaction_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $currencyId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();
        $userCurrencyId = (string) Str::ulid();

        DB::table('currencies')->insert([
            'id' => $currencyId,
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_categories')->insert([
            'id' => $categoryId,
            'user_id' => $user->id,
            'name' => 'Groceries',
            'type' => 'expense',
            'icon' => 'basket',
            'color' => '#ff0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_currencies')->insert([
            'id' => $userCurrencyId,
            'user_id' => $user->id,
            'currency_id' => $currencyId,
            'exchange_rate' => 1,
            'is_anchor' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = (string) Str::ulid();
        $transactionId = (string) Str::ulid();

        $payload = [
            'changes' => [
                [
                    'client_change_id' => 'c1',
                    'entity' => 'account',
                    'op' => 'create',
                    'id' => $accountId,
                    'data' => [
                        'user_currency_id' => $userCurrencyId,
                        'name' => 'Checking',
                        'type' => 'cash',
                        'initial_balance' => '10.50',
                        'is_default' => true,
                    ],
                ],
                [
                    'client_change_id' => 'c2',
                    'entity' => 'transaction',
                    'op' => 'create',
                    'id' => $transactionId,
                    'data' => [
                        'account_id' => $accountId,
                        'category_id' => $categoryId,
                        'exchange_rate_to_anchor' => '1.000000',
                        'type' => 'expense',
                        'amount' => '4.25',
                        'description' => 'Lunch',
                        'transaction_date' => '2026-07-09',
                    ],
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'applied')
            ->assertJsonPath('data.results.0.record.initial_balance', '10.50');

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transactionId, 'user_id' => $user->id]);

        // Read endpoint returns items under data with ISO-8601 timestamps and a derived balance
        // (initial 10.50 − 4.25 expense = 6.25), plus the transaction's derived currency_id.
        $read = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/accounts')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.balance', '6.25');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $read->json('data.items.0.created_at'),
        );

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/transactions')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.currency_id', $currencyId);
    }

    public function test_read_endpoints_and_sync_support_transfers_and_liability_payments(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $currencyId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();
        $userCurrencyId = (string) Str::ulid();
        $accountId = (string) Str::ulid();
        $liabilityId = (string) Str::ulid();
        $transferId = (string) Str::ulid();
        $paymentId = (string) Str::ulid();

        DB::table('currencies')->insert([
            'id' => $currencyId,
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('categories')->insert([
            'id' => $categoryId,
            'name' => 'Groceries',
            'type' => 'expense',
            'icon' => 'basket',
            'color' => '#ff0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_currencies')->insert([
            'id' => $userCurrencyId,
            'user_id' => $user->id,
            'currency_id' => $currencyId,
            'exchange_rate' => 1,
            'is_anchor' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('accounts')->insert([
            'id' => $accountId,
            'user_id' => $user->id,
            'user_currency_id' => $userCurrencyId,
            'name' => 'Savings',
            'type' => 'cash',
            'initial_balance' => '0',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountIdTwo = (string) Str::ulid();

        DB::table('accounts')->insert([
            'id' => $accountIdTwo,
            'user_id' => $user->id,
            'user_currency_id' => $userCurrencyId,
            'name' => 'Investment',
            'type' => 'bank_account',
            'initial_balance' => '0',
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/currencies')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/categories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items');

        $payload = [
            'changes' => [
                [
                    'client_change_id' => 't1',
                    'entity' => 'transfer',
                    'op' => 'create',
                    'id' => $transferId,
                    'data' => [
                        'from_account_id' => $accountId,
                        'to_account_id' => $accountIdTwo,
                        'from_amount' => '5.00',
                        'to_amount' => '5.00',
                        'exchange_rate' => '1.000000',
                        'fee' => '0.00',
                        'description' => 'Transfer',
                        'transfer_date' => '2026-07-09',
                    ],
                ],
                [
                    'client_change_id' => 'l1',
                    'entity' => 'liability',
                    'op' => 'create',
                    'id' => $liabilityId,
                    'data' => [
                        'user_currency_id' => $userCurrencyId,
                        'name' => 'Car Loan',
                        'type' => 'loan',
                        'principal_amount' => '100.00',
                        'interest_rate' => '2.50',
                        'due_date' => '2026-07-20',
                        'notes' => 'Note',
                        'is_settled' => false,
                    ],
                ],
                [
                    'client_change_id' => 'p1',
                    'entity' => 'liability_payment',
                    'op' => 'create',
                    'id' => $paymentId,
                    'data' => [
                        'liability_id' => $liabilityId,
                        'account_id' => $accountId,
                        'amount' => '10.00',
                        'payment_date' => '2026-07-09',
                        'note' => 'Paid',
                    ],
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'applied')
            ->assertJsonPath('data.results.2.status', 'applied');

        $this->assertDatabaseHas('transfers', ['id' => $transferId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('liabilities', ['id' => $liabilityId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('liability_payments', ['id' => $paymentId, 'liability_id' => $liabilityId]);
    }

    public function test_sync_pull_returns_initial_snapshot_and_delta_with_tombstones(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $currencyId = (string) Str::ulid();
        DB::table('currencies')->insert([
            'id' => $currencyId, 'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$',
            'decimal_places' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $userCurrencyId = (string) Str::ulid();
        $accountId = (string) Str::ulid();
        DB::table('user_currencies')->insert([
            'id' => $userCurrencyId, 'user_id' => $user->id, 'currency_id' => $currencyId,
            'exchange_rate' => 1, 'is_anchor' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('accounts')->insert([
            'id' => $accountId, 'user_id' => $user->id, 'user_currency_id' => $userCurrencyId,
            'name' => 'Cash', 'type' => 'cash', 'color' => '#64748B', 'initial_balance' => '0',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Initial pull (no `since`): full snapshot across collections.
        $initial = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.currencies')
            ->assertJsonCount(1, 'data.user_currencies')
            ->assertJsonCount(1, 'data.accounts');

        $cursor = $initial->json('data.server_time');
        $this->assertNotEmpty($cursor);

        // Nothing changed since the cursor → empty delta.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?since='.urlencode($cursor))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.accounts');

        // Soft-delete the account, then a delta pull must return it as a tombstone.
        // Advance the clock so updated_at is strictly after the cursor (stored at second precision).
        $this->travel(5)->seconds();
        DB::table('accounts')->where('id', $accountId)->update(['deleted_at' => now(), 'updated_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?since='.urlencode($cursor))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.id', $accountId)
            ->assertJsonPath('data.accounts.0.deleted_at', fn ($v) => $v !== null);
    }

    public function test_sync_push_creates_user_category_and_transaction_rejects_foreign_category(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $currencyId = (string) Str::ulid();
        $userCurrencyId = (string) Str::ulid();
        $accountId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();
        $transactionId = (string) Str::ulid();

        DB::table('currencies')->insert([
            'id' => $currencyId, 'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$',
            'decimal_places' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_currencies')->insert([
            'id' => $userCurrencyId, 'user_id' => $user->id, 'currency_id' => $currencyId,
            'exchange_rate' => 1, 'is_anchor' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('accounts')->insert([
            'id' => $accountId, 'user_id' => $user->id, 'user_currency_id' => $userCurrencyId,
            'name' => 'Cash', 'type' => 'cash', 'initial_balance' => '0', 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A category owned by a different user — must not be usable by this user.
        $otherUser = User::factory()->create();
        $foreignCategoryId = (string) Str::ulid();
        DB::table('user_categories')->insert([
            'id' => $foreignCategoryId, 'user_id' => $otherUser->id, 'name' => 'Theirs',
            'type' => 'expense', 'icon' => 'lock', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Push a user_category the client seeded, plus a transaction referencing it, plus one
        // referencing the foreign category (which must fail).
        $payload = [
            'changes' => [
                [
                    'client_change_id' => 'uc1',
                    'entity' => 'user_category',
                    'op' => 'create',
                    'id' => $categoryId,
                    'data' => ['name' => 'Groceries', 'type' => 'expense', 'icon' => 'basket', 'color' => '#ff0000'],
                ],
                [
                    'client_change_id' => 'tx-ok',
                    'entity' => 'transaction',
                    'op' => 'create',
                    'id' => $transactionId,
                    'data' => [
                        'account_id' => $accountId,
                        'category_id' => $categoryId,
                        'type' => 'expense',
                        'amount' => '4.25',
                        'transaction_date' => '2026-07-23',
                    ],
                ],
                [
                    'client_change_id' => 'tx-bad',
                    'entity' => 'transaction',
                    'op' => 'create',
                    'id' => (string) Str::ulid(),
                    'data' => [
                        'account_id' => $accountId,
                        'category_id' => $foreignCategoryId,
                        'type' => 'expense',
                        'amount' => '1.00',
                        'transaction_date' => '2026-07-23',
                    ],
                ],
            ],
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'applied')
            ->assertJsonPath('data.results.2.status', 'failed');

        $this->assertDatabaseHas('user_categories', ['id' => $categoryId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transactionId, 'category_id' => $categoryId]);

        // Pull returns the user's own categories (not the other user's).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.user_categories')
            ->assertJsonPath('data.user_categories.0.id', $categoryId);
    }

    public function test_global_reference_endpoints_are_public(): void
    {
        // No Authorization header — currencies and categories are open reference data.
        $this->getJson('/api/v1/currencies')->assertStatus(200)->assertJsonPath('success', true);
        $this->getJson('/api/v1/categories')->assertStatus(200)->assertJsonPath('success', true);

        // A protected endpoint still rejects anonymous access.
        $this->getJson('/api/v1/accounts')->assertStatus(401);
    }
}
