<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the hardcoded PaymentMethod -> '1100' routing (see
     * ReceiptEntry::cashAccountCode(), now removed) with a per-record chosen
     * Chart of Accounts row, mirroring PaymentEntry's own expense_account_id
     * shape exactly. payment_method itself is kept, not dropped — these are
     * real production rows and the column has historical meaning; it just
     * becomes nullable and stops being written going forward. Existing rows
     * backfill to whichever account currently has code '1100', the
     * historically honest value since that's genuinely what every past row
     * posted to regardless of its stored label.
     */
    public function up(): void
    {
        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->change();
        });

        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->foreignUuid('cash_account_id')->nullable()->after('receipt_date')->constrained('chart_of_accounts')->restrictOnDelete();
        });

        $cashAccountId = DB::table('chart_of_accounts')->where('code', '1100')->value('id');

        if ($cashAccountId !== null) {
            DB::table('receipt_entries')->update(['cash_account_id' => $cashAccountId]);
        }
    }

    public function down(): void
    {
        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_account_id');
        });

        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->string('payment_method')->nullable(false)->change();
        });
    }
};
