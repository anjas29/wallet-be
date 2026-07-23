<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repoint transactions.category_id from the global `categories` reference table
     * to the user-owned `user_categories` table. Kept as restrictOnDelete so a
     * category in use cannot be hard-deleted (retire via soft delete instead).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->foreign('category_id')
                ->references('id')->on('user_categories')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->foreign('category_id')
                ->references('id')->on('categories')
                ->restrictOnDelete();
        });
    }
};
