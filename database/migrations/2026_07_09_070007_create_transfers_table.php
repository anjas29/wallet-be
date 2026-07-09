<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // RESTRICT on both — force explicit handling instead of silently
            // wiping transfer history if an account is ever hard-deleted.
            $table->foreignUlid('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignUlid('to_account_id')->constrained('accounts')->restrictOnDelete();

            $table->decimal('from_amount', 15, 2)->unsigned(); // debited, source currency
            $table->decimal('to_amount', 15, 2)->unsigned();   // credited, destination currency

            // to_amount / from_amount, snapshot — never recalculated against
            // live rates later, even if same-currency (rate = 1 in that case).
            $table->decimal('exchange_rate', 15, 6)->nullable();

            $table->decimal('fee', 15, 2)->unsigned()->default(0);
            $table->text('description')->nullable();
            $table->date('transfer_date');

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']); // delta-sync
            $table->index('from_account_id');
            $table->index('to_account_id');
        });

        DB::statement('ALTER TABLE transfers ADD CONSTRAINT chk_transfers_from_amount_positive CHECK (from_amount > 0)');
        DB::statement('ALTER TABLE transfers ADD CONSTRAINT chk_transfers_to_amount_positive CHECK (to_amount > 0)');

        // Ensure a transfer never moves money to the same account it came from.
        DB::statement('ALTER TABLE transfers ADD CONSTRAINT chk_transfers_different_accounts CHECK (from_account_id != to_account_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
