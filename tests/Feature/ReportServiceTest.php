<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Currency;
use App\Models\Liability;
use App\Models\LiabilityPayment;
use App\Models\User;
use App\Models\UserCurrency;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_account_statement_merges_liability_payments_into_the_ledger(): void
    {
        $user = User::factory()->create();
        $currency = Currency::create(['code' => 'USD', 'name' => 'USD', 'symbol' => '$', 'decimal_places' => 2]);
        $userCurrency = UserCurrency::create([
            'user_id' => $user->id,
            'currency_id' => $currency->id,
            'exchange_rate' => 1,
            'is_anchor' => true,
        ]);
        $account = Account::create([
            'user_id' => $user->id,
            'user_currency_id' => $userCurrency->id,
            'name' => 'Checking',
            'type' => 'bank_account',
            'initial_balance' => '200.00',
            'is_default' => true,
        ]);
        $liability = Liability::create([
            'user_id' => $user->id,
            'user_currency_id' => $userCurrency->id,
            'name' => 'Car Loan',
            'type' => 'loan',
            'principal_amount' => '1000.00',
        ]);
        LiabilityPayment::create([
            'liability_id' => $liability->id,
            'account_id' => $account->id,
            'amount' => '75.00',
            'payment_date' => '2026-08-15',
            'note' => 'August installment',
        ]);

        $service = $this->app->make(ReportService::class);
        $method = new \ReflectionMethod($service, 'buildAccountStatement');
        $statement = $method->invoke($service, $account, null, null);

        $entry = $statement['entries']->firstWhere('type', 'liability_payment');

        $this->assertNotNull($entry, 'Expected a liability_payment entry in the merged ledger.');
        $this->assertSame('August installment', $entry['description']);
        $this->assertSame(-75.0, $entry['signedAmount']);
        $this->assertSame(125.0, (float) $statement['closing']); // 200 opening - 75 liability payment
        $this->assertSame(75.0, $statement['totalLiabilityPayment']);
    }
}
