<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    /**
     * Common ISO 4217 currencies. `decimal_places` follows the ISO minor-unit
     * (0 for JPY/KRW/VND, 3 for the Gulf dinars, 2 for the rest).
     *
     * @var list<array{code: string, name: string, symbol: string, decimal_places: int}>
     */
    private array $currencies = [
        ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2],
        ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2],
        ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimal_places' => 2],
        ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimal_places' => 0],
        ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'decimal_places' => 2],
        ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimal_places' => 2],
        ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'decimal_places' => 2],
        ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimal_places' => 2],
        ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'decimal_places' => 2],
        ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimal_places' => 2],
        ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'decimal_places' => 2],
        ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimal_places' => 2],
        ['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'decimal_places' => 2],
        ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimal_places' => 2],
        ['code' => 'THB', 'name' => 'Thai Baht', 'symbol' => '฿', 'decimal_places' => 2],
        ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'decimal_places' => 2],
        ['code' => 'VND', 'name' => 'Vietnamese Dong', 'symbol' => '₫', 'decimal_places' => 0],
        ['code' => 'KRW', 'name' => 'South Korean Won', 'symbol' => '₩', 'decimal_places' => 0],
        ['code' => 'TWD', 'name' => 'New Taiwan Dollar', 'symbol' => 'NT$', 'decimal_places' => 2],
        ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr', 'decimal_places' => 2],
        ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'decimal_places' => 2],
        ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'kr', 'decimal_places' => 2],
        ['code' => 'PLN', 'name' => 'Polish Zloty', 'symbol' => 'zł', 'decimal_places' => 2],
        ['code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč', 'decimal_places' => 2],
        ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'decimal_places' => 2],
        ['code' => 'BRL', 'name' => 'Brazilian Real', 'symbol' => 'R$', 'decimal_places' => 2],
        ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => 'Mex$', 'decimal_places' => 2],
        ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'decimal_places' => 2],
        ['code' => 'RUB', 'name' => 'Russian Ruble', 'symbol' => '₽', 'decimal_places' => 2],
        ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'decimal_places' => 2],
        ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼', 'decimal_places' => 2],
        ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'decimal_places' => 3],
        ['code' => 'BHD', 'name' => 'Bahraini Dinar', 'symbol' => '.د.ب', 'decimal_places' => 3],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->currencies as $currency) {
                Currency::updateOrCreate(
                    ['code' => $currency['code']],
                    [
                        'name' => $currency['name'],
                        'symbol' => $currency['symbol'],
                        'decimal_places' => $currency['decimal_places'],
                    ],
                );
            }
        });
    }
}
