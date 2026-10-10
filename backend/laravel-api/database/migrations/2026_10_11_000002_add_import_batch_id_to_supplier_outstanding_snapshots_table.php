<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AP mirror of 2026_10_11_000001_add_import_batch_id_to_customer_outstanding_snapshots_table -- see that migration's own docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_outstanding_snapshots', function (Blueprint $table) {
            $table->foreignUuid('import_batch_id')->nullable()->after('imported_by')->constrained('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_outstanding_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });
    }
};
