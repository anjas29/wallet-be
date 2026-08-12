<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Default global categories. Keyed on (name, type) for idempotency, since the
     * table has no unique constraint on the name.
     *
     * @var list<array{name: string, type: string, icon: string, color: string}>
     */
    private array $categories = [
        // Income
        ['name' => 'Salary', 'type' => 'income', 'icon' => 'briefcase', 'color' => '#D32F2F'],
        ['name' => 'Business', 'type' => 'income', 'icon' => 'store', 'color' => '#ED6C02'],
        ['name' => 'Investment', 'type' => 'income', 'icon' => 'trending-up', 'color' => '#ED6C02'],
        ['name' => 'Interest', 'type' => 'income', 'icon' => 'percent', 'color' => '#6C3400'],
        ['name' => 'Gift', 'type' => 'income', 'icon' => 'gift', 'color' => '#BA1A1A'],
        ['name' => 'Refund', 'type' => 'income', 'icon' => 'rotate-ccw', 'color' => '#6C3400'],
        ['name' => 'Other Income', 'type' => 'income', 'icon' => 'plus-circle', 'color' => '#2E7D32'],

        // Expense
        ['name' => 'Food & Drink', 'type' => 'expense', 'icon' => 'utensils', 'color' => '#6C3400'],
        ['name' => 'Groceries', 'type' => 'expense', 'icon' => 'shopping-basket', 'color' => '#24389C'],
        ['name' => 'Transport', 'type' => 'expense', 'icon' => 'bus', 'color' => '#5A5D72'],
        ['name' => 'Housing', 'type' => 'expense', 'icon' => 'home', 'color' => '#5A5D72'],
        ['name' => 'Utilities', 'type' => 'expense', 'icon' => 'zap', 'color' => '#BA1A1A'],
        ['name' => 'Health', 'type' => 'expense', 'icon' => 'heart-pulse', 'color' => '#D32F2F'],
        ['name' => 'Education', 'type' => 'expense', 'icon' => 'graduation-cap', 'color' => '#24389C'],
        ['name' => 'Entertainment', 'type' => 'expense', 'icon' => 'film', 'color' => '#6C3400'],
        ['name' => 'Shopping', 'type' => 'expense', 'icon' => 'shopping-bag', 'color' => '#5A5D72'],
        ['name' => 'Travel', 'type' => 'expense', 'icon' => 'plane', 'color' => '#ED6C02'],
        ['name' => 'Insurance', 'type' => 'expense', 'icon' => 'shield', 'color' => '#5A5D72'],
        ['name' => 'Taxes', 'type' => 'expense', 'icon' => 'receipt', 'color' => '#24389C'],
        ['name' => 'Fees & Charges', 'type' => 'expense', 'icon' => 'credit-card', 'color' => '#2E7D32'],
        ['name' => 'Other Expense', 'type' => 'expense', 'icon' => 'minus-circle', 'color' => '#5A5D72'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->categories as $category) {
                Category::updateOrCreate(
                    ['name' => $category['name'], 'type' => $category['type']],
                    [
                        'icon' => $category['icon'],
                        'color' => $category['color'],
                    ],
                );
            }
        });
    }
}
