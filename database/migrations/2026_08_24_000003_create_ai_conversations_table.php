<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // Derived from the first user message rather than asked for up front.
            $table->string('title')->nullable();

            // Ordering key for the chat list; kept separate from updated_at, which is the
            // delta-sync cursor and moves on a rename too.
            $table->timestamp('last_message_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']); // delta-sync
            $table->index(['user_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
