<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a snapshot point back at the ImportBatch that created it, so its rejected-rows CSV
 * (error_report_path, already written and never deleted by resolve()) stays downloadable from
 * Riwayat Import after the upload dialog closes -- previously only reachable during the preview
 * step itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_outstanding_snapshots', function (Blueprint $table) {
            $table->foreignUuid('import_batch_id')->nullable()->after('imported_by')->constrained('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_outstanding_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });
    }
};
