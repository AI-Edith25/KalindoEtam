<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes a real, manually-created Purchase Order from one fabricated by the Purchase
 * History import (a PO header + single placeholder line, see PurchaseHistoryImportService) — the
 * two "PurchaseOrder-with-placeholder-item" origins (Supplier Purchase Listing vs. Purchase Order
 * Tracking) are never conflated even though they share the same fabrication pattern:
 * import_source_type = 'historical_invoice' (File A) | 'po_tracking_amount' (File C) | null (real).
 *
 * amount_billed/outstanding_grn_value/outstanding_po_value are File C's own reported totals
 * (its AMOUNT BILLED / OUTSTD GRN / OUTSTD PO columns) — historical, pre-system POs have no live AP
 * trail to derive these from, so they're stored verbatim rather than computed.
 *
 * import_extra holds the optional legacy fields with nowhere else to live (File A's Reference #/
 * Reference 2 #; File C's Quote No/Request By/Requisition #/Supplier Invoice No/Supplier DO No).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('import_source_type')->nullable()->after('source_document_number');
            $table->json('import_extra')->nullable()->after('import_source_type');
            $table->decimal('amount_billed', 15, 2)->nullable()->after('import_extra');
            $table->decimal('outstanding_grn_value', 15, 2)->nullable()->after('amount_billed');
            $table->decimal('outstanding_po_value', 15, 2)->nullable()->after('outstanding_grn_value');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['import_source_type', 'import_extra', 'amount_billed', 'outstanding_grn_value', 'outstanding_po_value']);
        });
    }
};
