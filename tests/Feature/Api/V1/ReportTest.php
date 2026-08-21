<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\UserCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function authUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token];
    }

    private function fakeS3(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options = []) => "https://fake-bucket.s3.amazonaws.com/{$path}"
        );
    }

    private function seedAccount(User $user, string $currencyCode = 'USD', bool $anchor = true): Account
    {
        $currency = Currency::create(['code' => $currencyCode, 'name' => $currencyCode, 'symbol' => '$', 'decimal_places' => 2]);
        $userCurrency = UserCurrency::create([
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'exchange_rate' => 1,
            'is_anchor' => $anchor,
        ]);

        return Account::create([
            'user_id' => $user->id,
            'user_currency_id' => $userCurrency->id,
            'name' => 'Main Checking',
            'type' => 'bank_account',
            'initial_balance' => 100,
            'is_default' => true,
        ]);
    }

    private function seedTransaction(User $user, Account $account, string $type, string $amount, string $date): Transaction
    {
        $category = UserCategory::create(['user_id' => $user->id, 'name' => ucfirst($type), 'type' => $type, 'icon' => 'tag']);

        return Transaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => $type,
            'amount' => $amount,
            'description' => "Test {$type}",
            'transaction_date' => $date,
        ]);
    }

    public function test_it_generates_a_report_for_a_specific_month(): void
    {
        $this->fakeS3();
        [$user, $token] = $this->authUser();
        $account = $this->seedAccount($user);
        $this->seedTransaction($user, $account, 'income', '500.00', '2026-08-05');
        $this->seedTransaction($user, $account, 'expense', '120.00', '2026-08-10');
        // Outside the requested period — should not affect this report.
        $this->seedTransaction($user, $account, 'income', '999.00', '2026-01-15');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reports/transactions?period=2026-08');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.url', fn ($v) => is_string($v) && str_contains($v, 'reports/'.$user->id));

        $url = $response->json('data.url');
        $path = 'reports/'.$user->id.'/transactions-2026-08.pdf';

        $this->assertStringContainsString($path, $url);
        Storage::disk('s3')->assertExists($path);
    }

    public function test_it_generates_a_report_for_a_year(): void
    {
        $this->fakeS3();
        [$user, $token] = $this->authUser();
        $account = $this->seedAccount($user);
        $this->seedTransaction($user, $account, 'income', '500.00', '2026-08-05');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reports/transactions?period=2026')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        Storage::disk('s3')->assertExists('reports/'.$user->id.'/transactions-2026.pdf');
    }

    public function test_it_generates_an_all_time_report_when_period_is_omitted(): void
    {
        $this->fakeS3();
        [$user, $token] = $this->authUser();
        $account = $this->seedAccount($user);
        $this->seedTransaction($user, $account, 'income', '500.00', '2026-08-05');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reports/transactions')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        Storage::disk('s3')->assertExists('reports/'.$user->id.'/transactions-all-time.pdf');
    }

    public function test_it_includes_transfers_between_accounts(): void
    {
        $this->fakeS3();
        [$user, $token] = $this->authUser();
        $from = $this->seedAccount($user, 'USD');
        $to = Account::create([
            'user_id' => $user->id,
            'user_currency_id' => $from->user_currency_id,
            'name' => 'Savings',
            'type' => 'savings',
            'initial_balance' => 0,
            'is_default' => false,
        ]);

        Transfer::create([
            'user_id' => $user->id,
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'from_amount' => '50.00',
            'to_amount' => '50.00',
            'exchange_rate' => 1,
            'fee' => '1.00',
            'transfer_date' => '2026-08-12',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reports/transactions?period=2026-08')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        Storage::disk('s3')->assertExists('reports/'.$user->id.'/transactions-2026-08.pdf');
    }

    public function test_it_rejects_an_invalid_period_format(): void
    {
        $this->fakeS3();
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reports/transactions?period=not-a-period')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_it_rejects_an_invalid_month(): void
    {
        $this->fakeS3();
        [, $token] = $this->authUser();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reports/transactions?period=2026-13')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_report_requires_authentication(): void
    {
        $this->fakeS3();

        $this->getJson('/api/v1/reports/transactions')->assertStatus(401);
    }
}
