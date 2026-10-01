<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A separate, purely cosmetic "Location" field for every Invoice — distinct from the existing
 * `warehouse_id` column, which is NOT a display field: it's the discriminator Invoice::
 * isDirectGoodsInvoice() and StoreInvoiceRequest use to tell a Direct Goods invoice apart from a
 * Delivery-based one, and it drives real FIFO stock consumption for Direct invoices
 * (InvoiceService::assertSufficientStock()/fifoLayerService->consume()). Reusing it here would
 * both break that discriminator for Delivery-based invoices and risk a second (wrong) stock
 * deduction, so this is an independent nullable FK with zero stock/accounting side effects —
 * print-only, editable at any status (see InvoiceService::update()/updateSubmitted()).
 *
 * Backfilled from whatever Location data already exists: the anchor Delivery's warehouse_id for a
 * Delivery-based invoice, or the invoice's own warehouse_id for a Direct Goods invoice — matching
 * exactly what InvoicePrintPage.tsx already derives for the printed "Location" line today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('location_warehouse_id')->nullable()->after('warehouse_id')->constrained('warehouses')->nullOnDelete();
        });

        // A correlated subquery (not a multi-table UPDATE...JOIN) so this runs unchanged on both
        // MySQL (production) and SQLite (the test suite's DB_CONNECTION — see phpunit.xml).
        DB::statement(
            'UPDATE invoices SET location_warehouse_id = COALESCE('.
            '(SELECT warehouse_id FROM deliveries WHERE deliveries.id = invoices.delivery_id), '.
            'invoices.warehouse_id'.
            ')'
        );
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_warehouse_id');
        });
    }
};
