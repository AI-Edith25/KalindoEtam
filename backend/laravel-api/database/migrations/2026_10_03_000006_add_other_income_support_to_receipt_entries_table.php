<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors 2026_08_06_000001_add_general_expense_support_to_payment_entries_table.php exactly,
     * on the incoming side: customer_id becomes nullable (a nullable FK still enforces referential
     * integrity for any non-null value, it just also permits NULL — Other Income receipts have no
     * customer), payment_type distinguishes the two, and income_account_id/description are the
     * Other Income equivalent of expense_account_id/description.
     */
    public function up(): void
    {
        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable()->change();
        });

        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->string('payment_type')->default('customer')->after('customer_id');
            $table->foreignUuid('income_account_id')->nullable()->after('payment_type')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->text('description')->nullable()->after('income_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('income_account_id');
            $table->dropColumn(['payment_type', 'description']);
        });

        Schema::table('receipt_entries', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable(false)->change();
        });
    }
};
