<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The only structural way to distinguish Cash/Bank accounts from other
     * Asset accounts (Accounts Receivable, Inventory) for the Payment
     * Method picker — chart_of_accounts otherwise has no subtype. Backfills
     * known, confirmed-live production codes; a one-time migration data
     * decision, not the runtime name-sniffing this feature is replacing.
     */
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->boolean('is_cash_bank')->default(false)->after('account_type');
        });

        DB::table('chart_of_accounts')->whereIn('code', ['1000', '1100', '1101'])->update(['is_cash_bank' => true]);
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn('is_cash_bank');
        });
    }
};
