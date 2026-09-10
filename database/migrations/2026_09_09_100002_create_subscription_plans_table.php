<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local, provider-neutral plan metadata for display and feature-gating — separate from
     * Stripe's own Product/Price objects, which stay the source of truth for billing amounts.
     * Named `subscription_plans`, not `stripe_plans`, so a future alternative billing provider
     * (e.g. Google Play) can add its own product-id column without a rename.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_amount');
            $table->enum('interval', ['month', 'year']);
            $table->json('features')->nullable();

            // Free-trial length in days before the first charge. Not a Stripe Price attribute
            // (trials live on the subscription, not the Price) — set on the Stripe Checkout
            // session's `subscription_data.trial_period_days` at subscribe time instead.
            $table->unsignedSmallInteger('trial_days')->nullable();

            // Nullable: a plan can exist locally before `subscriptions:sync` has created its
            // Stripe Price and filled this in.
            $table->string('stripe_price_id')->nullable()->unique();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
