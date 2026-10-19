<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sibling nullable FKs, same shape as payment_entries.customer_id/supplier_id. */
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->nullable()->after('branch_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->after('customer_id')->constrained('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
