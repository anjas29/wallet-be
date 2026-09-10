<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashier's own subscription-item ledger, ULID-customized (see create_subscriptions_table).
     * `meter_id` / `meter_event_name` support Stripe's usage-based billing; Cashier ships them as
     * separate migrations, folded into this one since this project prefers one migration per
     * table rather than a long tail of incremental alters.
     */
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')->constrained()->cascadeOnDelete();

            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
