<?php

namespace Tests\Feature\Api\V1;

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

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ada@example.com');
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
            'type' => 'bank',
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
}
