<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_delete_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // The email as typed on the public form. Kept even when no account matches it:
            // the form must not reveal whether an address is registered, so every submission
            // is recorded and the operator sees "no matching account" in the panel instead.
            $table->string('email')->index();

            // NULL ON DELETE, not CASCADE: the request is an audit record of a legal ask and
            // has to outlive the account it points at (including a later hard delete).
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', ['pending', 'ignored', 'deleted'])->default('pending');

            // Who acted on it, and when. Both NULL while pending.
            $table->foreignUlid('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();

            // Request provenance — the only evidence available for an unauthenticated form.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_delete_requests');
    }
};
