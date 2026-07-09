<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_currencies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('currency_id')->constrained()->restrictOnDelete();

            $table->decimal('exchange_rate', 20, 6)->default(1);
            $table->boolean('is_anchor')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'currency_id']);
        });

        // Extra safety net: no user_currencies row should ever be deleted,
        // not just the anchor — accounts/liabilities/transactions may reference
        // any of them historically. No DELETE endpoint is exposed at the app
        // layer either; this constraint-level guard is a defense-in-depth backstop.
        // (Enforced primarily via Eloquent model `deleting` event guard in code.)
    }

    public function down(): void
    {
        Schema::dropIfExists('user_currencies');
    }
};
