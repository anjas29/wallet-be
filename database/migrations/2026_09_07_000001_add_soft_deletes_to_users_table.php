<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account deletion requests are honoured as a soft delete, so the users table needs a
 * `deleted_at`. Eloquent's user provider applies global scopes, so once User uses
 * SoftDeletes a trashed row can no longer authenticate on any guard (web or sanctum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
