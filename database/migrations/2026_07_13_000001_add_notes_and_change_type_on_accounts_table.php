<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Optional free-text notes for the account.
            $table->text('notes')->nullable()->after('name');
        });

        $this->changeTypeConstraint(
            newValues: ['bank_account', 'cash', 'credit_card', 'savings'],
            remap: function () {
                // `bank` maps cleanly; `e_wallet`/`other` have no equivalent
                // and fall back to `cash`.
                DB::table('accounts')->where('type', 'bank')->update(['type' => 'bank_account']);
                DB::table('accounts')->whereIn('type', ['e_wallet', 'other'])->update(['type' => 'cash']);
            },
        );
    }

    public function down(): void
    {
        $this->changeTypeConstraint(
            newValues: ['cash', 'bank', 'e_wallet', 'other'],
            remap: function () {
                DB::table('accounts')->where('type', 'bank_account')->update(['type' => 'bank']);
                DB::table('accounts')->whereIn('type', ['credit_card', 'savings'])->update(['type' => 'other']);
            },
        );

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }

    /**
     * Repoint the `accounts.type` enum at a new set of allowed values, running
     * `$remap` while no constraint is active so existing rows can be migrated
     * onto the new values without violating the old or new constraint.
     *
     * Laravel models enum as `varchar` + a CHECK constraint on PostgreSQL, but
     * `enum()->change()` emits invalid SQL there, so swap the constraint by hand.
     *
     * @param  list<string>  $newValues
     */
    private function changeTypeConstraint(array $newValues, callable $remap): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE accounts DROP CONSTRAINT IF EXISTS accounts_type_check');

            $remap();

            $allowed = collect($newValues)->map(fn ($v) => "'".$v."'")->implode(', ');
            DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ($allowed))");

            return;
        }

        $remap();

        Schema::table('accounts', function (Blueprint $table) use ($newValues) {
            $table->enum('type', $newValues)->change();
        });
    }
};
