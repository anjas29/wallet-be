<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('account_id')->constrained()->restrictOnDelete();
            // Explicitly user_categories (not the global `categories` table) — matches transactions.category_id.
            $table->foreignUlid('category_id')->constrained('user_categories')->restrictOnDelete();

            $table->decimal('amount', 15, 2)->unsigned();
            $table->text('description')->nullable();

            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'yearly']);

            $table->date('start_date');
            $table->date('end_date')->nullable(); // optional stop condition

            // Server-owned bookkeeping for the scheduled job; nullable because it's cleared once
            // the template is retired (end_date passed / deactivated) — no further run is due.
            $table->date('next_run_date')->nullable();

            $table->boolean('is_active')->default(true); // pause without deleting

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']); // delta-sync
            $table->index(['is_active', 'next_run_date']); // the scheduled job's due-query
        });

        DB::statement('ALTER TABLE recurring_transactions ADD CONSTRAINT chk_recurring_transactions_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_transactions');
    }
};
