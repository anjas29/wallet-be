<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private function seedBaseline(User $user): array
    {
        $currencyId = (string) Str::ulid();
        $userCurrencyId = (string) Str::ulid();
        $accountId = (string) Str::ulid();
        $expenseCategoryId = (string) Str::ulid();
        $incomeCategoryId = (string) Str::ulid();

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
            'id' => $expenseCategoryId, 'user_id' => $user->id, 'name' => 'Groceries',
            'type' => 'expense', 'icon' => 'basket', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_categories')->insert([
            'id' => $incomeCategoryId, 'user_id' => $user->id, 'name' => 'Salary',
            'type' => 'income', 'icon' => 'cash', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('accountId', 'expenseCategoryId', 'incomeCategoryId');
    }

    public function test_sync_push_creates_monthly_budget_and_read_endpoint_computes_spent_and_remaining(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        ['accountId' => $accountId, 'expenseCategoryId' => $categoryId] = $this->seedBaseline($user);

        $budgetId = (string) Str::ulid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [[
                    'client_change_id' => 'b1',
                    'entity' => 'budget',
                    'op' => 'create',
                    'id' => $budgetId,
                    'data' => ['category_id' => $categoryId, 'amount' => '500.00', 'period_type' => 'monthly'],
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'applied');

        $this->assertDatabaseHas('budgets', ['id' => $budgetId, 'user_id' => $user->id, 'period_type' => 'monthly']);

        // A transaction inside the current month counts toward spent; one from last month doesn't.
        $inMonth = Carbon::now()->startOfMonth()->addDays(2);
        $lastMonth = Carbon::now()->startOfMonth()->subDay();

        DB::table('transactions')->insert([
            [
                'id' => (string) Str::ulid(), 'user_id' => $user->id, 'account_id' => $accountId,
                'category_id' => $categoryId, 'type' => 'expense', 'amount' => '40.00',
                'transaction_date' => $inMonth->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(), 'user_id' => $user->id, 'account_id' => $accountId,
                'category_id' => $categoryId, 'type' => 'expense', 'amount' => '999.00',
                'transaction_date' => $lastMonth->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/budgets/'.$budgetId)
            ->assertStatus(200)
            ->assertJsonPath('data.spent', '40.00')
            ->assertJsonPath('data.remaining', '460.00');
    }

    public function test_sync_push_creates_custom_period_budget(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        ['expenseCategoryId' => $categoryId] = $this->seedBaseline($user);

        $budgetId = (string) Str::ulid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [[
                    'client_change_id' => 'b2',
                    'entity' => 'budget',
                    'op' => 'create',
                    'id' => $budgetId,
                    'data' => [
                        'category_id' => $categoryId,
                        'amount' => '1200.00',
                        'period_type' => 'custom',
                        'period_start' => '2026-08-01',
                        'period_end' => '2026-08-31',
                    ],
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'applied');

        $this->assertDatabaseHas('budgets', [
            'id' => $budgetId,
            'period_type' => 'custom',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ]);
    }

    public function test_budget_rejects_income_category(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        ['incomeCategoryId' => $categoryId] = $this->seedBaseline($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [[
                    'client_change_id' => 'b3',
                    'entity' => 'budget',
                    'op' => 'create',
                    'id' => (string) Str::ulid(),
                    'data' => ['category_id' => $categoryId, 'amount' => '100.00', 'period_type' => 'monthly'],
                ]],
            ]);

        $response->assertStatus(200)->assertJsonPath('data.results.0.status', 'failed');

        $errors = $response->json('data.results.0.error.errors');
        $this->assertSame(
            ['A budget can only be set on an expense category.'],
            $errors['data.category_id'],
        );
    }

    public function test_custom_period_budget_requires_both_dates(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        ['expenseCategoryId' => $categoryId] = $this->seedBaseline($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'changes' => [[
                    'client_change_id' => 'b4',
                    'entity' => 'budget',
                    'op' => 'create',
                    'id' => (string) Str::ulid(),
                    'data' => [
                        'category_id' => $categoryId,
                        'amount' => '100.00',
                        'period_type' => 'custom',
                        'period_start' => '2026-08-01',
                    ],
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.status', 'failed');
    }
}
