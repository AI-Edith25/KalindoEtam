<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks an account as a control account requiring a Sub Ledger
     * (customer/vendor) on every journal line posted to it — mirrors
     * SkyBiz's own AR/AP control-account behavior. 112.01 "Piutang Usaha"
     * and 210.01 "Hutang Supplier" are the only two control accounts today
     * (see ChartOfAccountsSeeder); backfilled directly here so production
     * gets it on migrate without depending on the seeder re-running.
     */
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('subledger_type')->nullable()->after('parent_id');
        });

        DB::table('chart_of_accounts')->where('code', '112.01')->update(['subledger_type' => 'customer']);
        DB::table('chart_of_accounts')->where('code', '210.01')->update(['subledger_type' => 'vendor']);
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('subledger_type');
        });
    }
};
