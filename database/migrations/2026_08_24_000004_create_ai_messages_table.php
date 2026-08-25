<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();

            // Denormalised on purpose: liability_payments omitted user_id and now pays for it
            // with a whereHas on every read (LiabilityPaymentService::ownedQuery()).
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->enum('role', ['user', 'assistant']);
            $table->text('content');

            // Set when a stream died mid-answer, so the client can show a retry affordance
            // against an otherwise-empty assistant turn.
            $table->string('error')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']); // transcript read
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
