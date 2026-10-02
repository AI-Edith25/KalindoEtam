<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an operator switch an imported historical Invoice (import_source_type !== null) to
 * actually consume FIFO stock from its own location_warehouse_id, via editing — see
 * Invoice::stockWarehouseId()/movesStock() and InvoiceService::updateSubmitted(). Defaults
 * false for every invoice (including every already-imported one), matching today's behavior
 * exactly: nothing changes until an operator explicitly flips it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('affects_stock')->default(false)->after('location_warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('affects_stock');
        });
    }
};
