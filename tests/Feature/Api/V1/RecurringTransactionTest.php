<?php

namespace Tests\Feature\Api\V1;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecurringTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function seedBaseline(User $user): array
    {
        $currencyId = (string) Str::ulid();
        $userCurrencyId = (string) Str::ulid();
        $accountId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();

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
            'name' => 'Checking', 'type' => 'bank_account', 'initial_balance' => '0', 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_categories')->insert([
            'id' => $categoryId, 'user_id' => $user->id, 'name' => 'Subscriptions',
            'type' => 'expense', 'icon' => 'repeat', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('accountId', 'categoryId');
    }

    public function test_sync_push_creates_recurring_transaction_template(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        ['accountId' => $accountId, 'categoryId' => $categoryId] = $this->seedBaseline($user);

        $id = (string) Str::ulid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [[
                    'client_change_id' => 'r1',
                    'entity' => 'recurring_transaction',
                    'op' => 'create',
                    'id' => $id,
                    'data' => [
                        'account_id' => $accountId,
                        'category_id' => $categoryId,
                        'amount' => '15.00',
                        'description' => 'Netflix',
                        'frequency' => 'monthly',
                        'start_date' => '2026-09-01',
                    ],
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.record.next_run_date', '2026-09-01');

        $this->assertDatabaseHas('recurring_transactions', [
            'id' => $id,
            'user_id' => $user->id,
            'next_run_date' => '2026-09-01',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/recurring-transactions')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_sync_push_rejects_invalid_frequency_and_foreign_account(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        ['accountId' => $accountId, 'categoryId' => $categoryId] = $this->seedBaseline($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [[
                    'client_change_id' => 'r2',
                    'entity' => 'recurring_transaction',
                    'op' => 'create',
                    'id' => (string) Str::ulid(),
                    'data' => [
                        'account_id' => $accountId,
                        'category_id' => $categoryId,
                        'amount' => '15.00',
                        'frequency' => 'fortnightly',
                        'start_date' => '2026-09-01',
                    ],
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'failed');

        // An account id that doesn't belong to (or exist for) this user.
        $foreignAccountId = (string) Str::ulid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [[
                    'client_change_id' => 'r3',
                    'entity' => 'recurring_transaction',
                    'op' => 'create',
                    'id' => (string) Str::ulid(),
                    'data' => [
                        'account_id' => $foreignAccountId,
                        'category_id' => $categoryId,
                        'amount' => '15.00',
                        'frequency' => 'monthly',
                        'start_date' => '2026-09-01',
                    ],
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'failed');
    }

    public function test_generate_command_creates_due_transaction_catches_up_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        ['accountId' => $accountId, 'categoryId' => $categoryId] = $this->seedBaseline($user);

        $today = Carbon::today();
        $threeDaysAgo = $today->copy()->subDays(3);

        $template = RecurringTransaction::create([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'amount' => '10.00',
            'description' => 'Daily coffee',
            'frequency' => 'daily',
            'start_date' => $threeDaysAgo->toDateString(),
            'next_run_date' => $threeDaysAgo->toDateString(),
            'is_active' => true,
        ]);

        Artisan::call('recurring-transactions:generate');

        // Catch-up: one occurrence per day from 3 days ago through today = 4 transactions.
        $this->assertSame(4, Transaction::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'type' => 'expense',
            'amount' => '10.00',
        ]);

        $template->refresh();
        $this->assertSame($today->copy()->addDay()->toDateString(), $template->next_run_date->toDateString());

        // Running again immediately must not double-generate (next_run_date is now in the future).
        Artisan::call('recurring-transactions:generate');
        $this->assertSame(4, Transaction::where('user_id', $user->id)->count());
    }

    public function test_generate_command_skips_template_with_soft_deleted_account(): void
    {
        $user = User::factory()->create();
        ['accountId' => $accountId, 'categoryId' => $categoryId] = $this->seedBaseline($user);

        RecurringTransaction::create([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'amount' => '10.00',
            'frequency' => 'daily',
            'start_date' => Carbon::today()->toDateString(),
            'next_run_date' => Carbon::today()->toDateString(),
            'is_active' => true,
        ]);

        DB::table('accounts')->where('id', $accountId)->update(['deleted_at' => now()]);

        Artisan::call('recurring-transactions:generate');

        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());
    }
}
