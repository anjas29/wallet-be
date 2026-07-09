<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_syncs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id'); // matches refresh_tokens.device_id

            // Snapshot the timestamp BEFORE fetching sync data, not after —
            // otherwise writes that happen mid-sync can be silently skipped
            // on the next delta pull. Only persisted once the full paginated
            // sync sequence completes successfully (never partially).
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_syncs');
    }
};
