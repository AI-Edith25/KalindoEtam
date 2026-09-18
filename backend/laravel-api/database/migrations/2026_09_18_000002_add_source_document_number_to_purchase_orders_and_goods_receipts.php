<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the legacy document number a Purchase Order/Goods Receipt was imported from (a PO NO. or
 * a DOCUMENT # like "SI-26-0545") — see PurchaseHistoryImportService. Neither table has any
 * free-text external reference column otherwise, so this plays the same role
 * journal_entries.source_document_number already plays for the Journal List/Trial Balance/Income
 * Statement/Balance Sheet importers: legacy-import duplicate detection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('source_document_number')->nullable()->after('remarks')->index();
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('source_document_number')->nullable()->after('remarks')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('source_document_number');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('source_document_number');
        });
    }
};
