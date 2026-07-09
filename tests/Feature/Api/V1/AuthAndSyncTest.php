<?php

namespace Tests\Feature\Api\V1;

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
            ->assertJsonPath('user.email', 'ada@example.com');

        $token = $response->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(200)
            ->assertJsonPath('user.email', 'ada@example.com');
    }

    public function test_sync_push_can_create_account_and_transaction_for_authenticated_user(): void
    {
        $user = \App\Models\User::factory()->create();
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
                        'currency_id' => $currencyId,
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
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied');

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transactionId, 'user_id' => $user->id]);
    }

    public function test_read_endpoints_and_sync_support_transfers_and_liability_payments(): void
    {
        $user = \App\Models\User::factory()->create();
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
            ->assertJsonCount(1, 'data');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/categories')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

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
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied')
            ->assertJsonPath('results.2.status', 'applied');

        $this->assertDatabaseHas('transfers', ['id' => $transferId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('liabilities', ['id' => $liabilityId, 'user_id' => $user->id]);
        $this->assertDatabaseHas('liability_payments', ['id' => $paymentId, 'liability_id' => $liabilityId]);
    }
}
