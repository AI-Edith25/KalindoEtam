<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional Branch on Incoming Payment, needed so the Journal List report
     * can filter cash/bank transactions by Branch — journal_entry_lines.branch_id
     * is never populated by any business module, so Branch must be captured
     * here instead. Nullable: existing rows have none, and Branch stays
     * optional on this workflow (not required, unlike sales_orders.branch_id).
     */
    public function up(): void
    {
        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->nullable()->after('cash_account_id')->constrained('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
