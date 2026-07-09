<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            // Shared across all tokens descending from one login ("session lineage").
            // On reuse of an already-revoked token, revoke the whole family_id at once.
            $table->ulid('family_id');

            $table->string('token_hash')->unique(); // SHA-256 hash, never store raw token
            $table->string('device_id');             // from X-Device-Id header, generated once by app
            $table->string('device_name')->nullable(); // e.g. "John's iPhone 15 Pro"
            $table->string('ip_address')->nullable();   // anomaly logging

            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index('family_id');
            $table->index(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
