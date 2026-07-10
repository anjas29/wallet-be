<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('account_id')->constrained()->restrictOnDelete();

            // RESTRICT: protect global reference data — retire via soft delete instead.
            $table->foreignUlid('category_id')->constrained()->restrictOnDelete();

            // A transaction is always in its account's currency (via account.user_currency),
            // so no per-transaction currency is stored. This snapshots the account-currency →
            // anchor rate at creation time, so historical reports stay accurate even if the
            // user later adjusts their user_currencies.exchange_rate.
            $table->decimal('exchange_rate_to_anchor', 20, 6)->default(1);

            // Denormalized from category for fast filtering without a join.
            $table->enum('type', ['income', 'expense']);

            $table->decimal('amount', 15, 2)->unsigned();
            $table->text('description')->nullable();
            $table->date('transaction_date'); // literal date as sent by client, no TZ conversion

            $table->softDeletes();
            $table->timestamps();

            // Monthly/yearly report queries
            $table->index(['user_id', 'transaction_date']);
            // Top-categories aggregation
            $table->index(['user_id', 'category_id']);
            $table->index('account_id');
            // Delta-sync query shape
            $table->index(['user_id', 'updated_at']);
        });

        // Defense-in-depth: amount must be strictly positive at the DB level,
        // direction is already handled by the `type` column, never a signed amount.
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transactions_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
