<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // RESTRICT: a budget references a category's transaction history — retire the
            // category via soft delete instead of silently orphaning budgets.
            $table->foreignUlid('category_id')->constrained('user_categories')->restrictOnDelete();

            $table->decimal('amount', 15, 2)->unsigned(); // the budget cap

            $table->enum('period_type', ['monthly', 'custom']);

            // Only set when period_type = 'custom'; null for 'monthly' (always resolved against
            // "the current calendar month" at read time — see BudgetService::resolvePeriod()).
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']); // delta-sync
            $table->index(['user_id', 'category_id']);
        });

        DB::statement('ALTER TABLE budgets ADD CONSTRAINT chk_budgets_amount_positive CHECK (amount > 0)');

        // monthly must have neither date; custom must have both, with end >= start.
        DB::statement(<<<'SQL'
            ALTER TABLE budgets ADD CONSTRAINT chk_budgets_period_shape CHECK (
                (period_type = 'monthly' AND period_start IS NULL AND period_end IS NULL)
                OR
                (period_type = 'custom' AND period_start IS NOT NULL AND period_end IS NOT NULL AND period_end >= period_start)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
