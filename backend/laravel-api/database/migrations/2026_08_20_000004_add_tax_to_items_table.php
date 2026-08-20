<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Both nullable — "empty = No tax," same as every document-level tax_id
 * before it. purchase_tax_id/sales_tax_id are only ever validated against
 * a Tax of the matching transaction_type at the request layer (see
 * StoreItemRequest/UpdateItemRequest), not enforced by a DB constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignUuid('purchase_tax_id')->nullable()->after('standard_rate')->constrained('taxes')->nullOnDelete();
            $table->foreignUuid('sales_tax_id')->nullable()->after('purchase_tax_id')->constrained('taxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_tax_id');
            $table->dropConstrainedForeignId('sales_tax_id');
        });
    }
};
