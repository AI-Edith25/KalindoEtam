<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->nullable()->after('sales_order_id')->constrained('branches')->restrictOnDelete();
        });

        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->nullable()->after('sales_order_id')->constrained('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('accounts_receivables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
