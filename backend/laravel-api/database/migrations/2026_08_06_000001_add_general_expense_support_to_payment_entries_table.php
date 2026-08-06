<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * supplier_id becomes nullable — safe because a nullable FK column still
     * enforces referential integrity for any non-null value, it just also
     * permits NULL (General Expense payments have no supplier). Laravel 11+
     * altering columns natively, no doctrine/dbal needed.
     */
    public function up(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->uuid('supplier_id')->nullable()->change();
        });

        Schema::table('payment_entries', function (Blueprint $table) {
            $table->string('payment_type')->default('supplier')->after('supplier_id');
            $table->foreignUuid('expense_account_id')->nullable()->after('payment_type')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->text('description')->nullable()->after('expense_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_account_id');
            $table->dropColumn(['payment_type', 'description']);
        });

        Schema::table('payment_entries', function (Blueprint $table) {
            $table->uuid('supplier_id')->nullable(false)->change();
        });
    }
};
