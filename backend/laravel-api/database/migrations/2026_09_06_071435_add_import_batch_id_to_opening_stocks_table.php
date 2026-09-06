<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groups every document created by one Opening Stock import upload — the same
     * ImportBatch row is already this app's natural "one file upload" identity (see
     * OpeningStockImportTemplate), so this just tags each created document with it instead of
     * inventing a separate batch concept. Null for a manually-created (non-imported) document.
     */
    public function up(): void
    {
        Schema::table('opening_stocks', function (Blueprint $table) {
            $table->foreignUuid('import_batch_id')->nullable()->after('remarks')->constrained('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opening_stocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });
    }
};
