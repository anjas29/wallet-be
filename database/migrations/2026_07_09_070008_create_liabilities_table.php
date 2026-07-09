<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liabilities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_currency_id')->constrained('user_currencies')->restrictOnDelete();

            $table->string('name'); // "Car Loan"
            $table->enum('type', ['loan', 'credit_card', 'personal']);
            $table->decimal('principal_amount', 15, 2)->unsigned();

            // Display-only — never used in remaining/balance calculations.
            $table->decimal('interest_rate', 5, 2)->nullable();

            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_settled')->default(false);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']); // delta-sync
        });

        DB::statement('ALTER TABLE liabilities ADD CONSTRAINT chk_liabilities_principal_positive CHECK (principal_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('liabilities');
    }
};
