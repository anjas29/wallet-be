<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liability_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // CASCADE: deleting a liability permanently removes its payment
            // history — matches the mobile UI's explicit delete-confirmation copy.
            $table->foreignUlid('liability_id')->constrained()->cascadeOnDelete();

            // RESTRICT: every payment must debit a real account; force explicit
            // handling instead of silently losing payment history on account deletion.
            $table->foreignUlid('account_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 15, 2)->unsigned();
            $table->date('payment_date');
            $table->text('note')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['liability_id', 'updated_at']); // delta-sync
            $table->index('account_id');
        });

        DB::statement('ALTER TABLE liability_payments ADD CONSTRAINT chk_liability_payments_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('liability_payments');
    }
};
