<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Repoint transactions.category_id from the global `categories` reference table to the
     * user-owned `user_categories` table.
     *
     * Backfill: existing transactions reference global categories, which don't exist in
     * `user_categories`. For each (user, global category) pair actually used by a transaction,
     * create a user-owned copy and remap those transactions to it — then swap the FK. Runs in a
     * single transaction (Postgres DDL is transactional), so it's all-or-nothing.
     */
    public function up(): void
    {
        // 1) Drop the old FK first, so the remap below can point at user_categories ids
        //    (which don't exist in `categories`) without tripping the constraint.
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        // 2) Backfill + remap. Raw queries intentionally include soft-deleted transactions,
        //    since the FK applies to every physical row.
        $pairs = DB::table('transactions')
            ->select('user_id', 'category_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $global = DB::table('categories')->where('id', $pair->category_id)->first();

            // The old FK guaranteed the global row existed, so this is defensive only.
            if ($global === null) {
                continue;
            }

            $newId = (string) Str::ulid();

            DB::table('user_categories')->insert([
                'id' => $newId,
                'user_id' => $pair->user_id,
                'name' => $global->name,
                'type' => $global->type,
                'icon' => $global->icon,
                'color' => $global->color,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('transactions')
                ->where('user_id', $pair->user_id)
                ->where('category_id', $pair->category_id)
                ->update(['category_id' => $newId]);
        }

        // 3) Add the new FK to user_categories (kept restrictOnDelete — retire via soft delete).
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')->on('user_categories')
                ->restrictOnDelete();
        });
    }

    /**
     * Best-effort reverse: point each transaction back to a global category matched by
     * (name, type), then restore the original FK. A user-renamed category with no global
     * match cannot be mapped back and would fail the FK — acceptable for a rollback.
     * Backfilled `user_categories` rows are left in place (may hold user-added data).
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        $rows = DB::table('transactions as t')
            ->join('user_categories as uc', 't.category_id', '=', 'uc.id')
            ->select('t.id as tid', 'uc.name', 'uc.type')
            ->get();

        foreach ($rows as $row) {
            $global = DB::table('categories')
                ->where('name', $row->name)
                ->where('type', $row->type)
                ->first();

            if ($global !== null) {
                DB::table('transactions')->where('id', $row->tid)->update(['category_id' => $global->id]);
            }
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')->on('categories')
                ->restrictOnDelete();
        });
    }
};
