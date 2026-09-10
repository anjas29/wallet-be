<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `interval_count` mirrors Stripe's own `recurring.interval_count` (e.g. interval=month,
     * interval_count=3 for quarterly) — without it, only whole months/years are representable.
     *
     * `is_anchor` designates the one plan every other plan's `price_saved`/`best_value` (see
     * SubscriptionService) is normalized against — same one-flag-at-a-time convention as
     * `user_currencies.is_anchor`.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('interval_count')->default(1)->after('interval');
            $table->boolean('is_anchor')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['interval_count', 'is_anchor']);
        });
    }
};
