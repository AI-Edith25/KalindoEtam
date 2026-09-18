<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the legacy document number a Journal Entry was imported from (e.g. "SI0016/10/2022",
 * "PI-SVC-KE-00349") — see SalesPurchaseJournalImportService. JournalEntry has no free-text
 * external reference column otherwise (only the polymorphic reference_type/reference_id pair),
 * so this plays the same role reference_number already plays on PaymentEntry/ReceiptEntry for
 * legacy-import duplicate detection, and doubles as safe resume-after-failure for a large file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source_document_number')->nullable()->after('description')->index();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn('source_document_number');
        });
    }
};
