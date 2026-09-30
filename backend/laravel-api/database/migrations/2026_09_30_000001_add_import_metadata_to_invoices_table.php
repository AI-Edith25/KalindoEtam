<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the legacy document number an Invoice was imported from (an "SI/KE/..." or "TR/KE/..."
 * number, see SalesInvoiceImportService) — same role source_document_number already plays on
 * purchase_orders/goods_receipts/journal_entries: legacy-import duplicate detection.
 *
 * import_source_type distinguishes an imported historical Invoice from a real one (null) —
 * InvoiceService::submit()/cancel() key off it to skip stock/AR/GL side effects entirely for
 * these rows, since AR/GL balances are already backfilled by a separate Customer Outstanding
 * import. import_extra holds legacy fields with nowhere else to live (customer code as typed in
 * the file, raw header DISC/TAX/AMOUNT for audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('source_document_number')->nullable()->after('remarks')->index();
            $table->string('import_source_type')->nullable()->after('source_document_number');
            $table->json('import_extra')->nullable()->after('import_source_type');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['source_document_number', 'import_source_type', 'import_extra']);
        });
    }
};
