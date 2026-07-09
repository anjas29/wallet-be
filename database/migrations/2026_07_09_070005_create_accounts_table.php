<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('user_currency_id')->constrained('user_currencies')->restrictOnDelete();

            $table->string('name');
            $table->enum('type', ['cash', 'bank', 'e_wallet', 'other']);
            $table->decimal('initial_balance', 15, 2)->default(0);
            $table->boolean('is_default')->default(false);

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
