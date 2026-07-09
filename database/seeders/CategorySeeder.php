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
        ['name' => 'Salary', 'type' => 'income', 'icon' => 'briefcase', 'color' => '#2E7D32'],
        ['name' => 'Business', 'type' => 'income', 'icon' => 'store', 'color' => '#388E3C'],
        ['name' => 'Investment', 'type' => 'income', 'icon' => 'trending-up', 'color' => '#43A047'],
        ['name' => 'Interest', 'type' => 'income', 'icon' => 'percent', 'color' => '#4CAF50'],
        ['name' => 'Gift', 'type' => 'income', 'icon' => 'gift', 'color' => '#66BB6A'],
        ['name' => 'Refund', 'type' => 'income', 'icon' => 'rotate-ccw', 'color' => '#81C784'],
        ['name' => 'Other Income', 'type' => 'income', 'icon' => 'plus-circle', 'color' => '#A5D6A7'],

        // Expense
        ['name' => 'Food & Drink', 'type' => 'expense', 'icon' => 'utensils', 'color' => '#E53935'],
        ['name' => 'Groceries', 'type' => 'expense', 'icon' => 'shopping-basket', 'color' => '#D81B60'],
        ['name' => 'Transport', 'type' => 'expense', 'icon' => 'bus', 'color' => '#8E24AA'],
        ['name' => 'Housing', 'type' => 'expense', 'icon' => 'home', 'color' => '#5E35B1'],
        ['name' => 'Utilities', 'type' => 'expense', 'icon' => 'zap', 'color' => '#3949AB'],
        ['name' => 'Health', 'type' => 'expense', 'icon' => 'heart-pulse', 'color' => '#1E88E5'],
        ['name' => 'Education', 'type' => 'expense', 'icon' => 'graduation-cap', 'color' => '#039BE5'],
        ['name' => 'Entertainment', 'type' => 'expense', 'icon' => 'film', 'color' => '#00ACC1'],
        ['name' => 'Shopping', 'type' => 'expense', 'icon' => 'shopping-bag', 'color' => '#00897B'],
        ['name' => 'Travel', 'type' => 'expense', 'icon' => 'plane', 'color' => '#43A047'],
        ['name' => 'Insurance', 'type' => 'expense', 'icon' => 'shield', 'color' => '#7CB342'],
        ['name' => 'Taxes', 'type' => 'expense', 'icon' => 'receipt', 'color' => '#C0CA33'],
        ['name' => 'Fees & Charges', 'type' => 'expense', 'icon' => 'credit-card', 'color' => '#FB8C00'],
        ['name' => 'Other Expense', 'type' => 'expense', 'icon' => 'minus-circle', 'color' => '#6D4C41'],
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
