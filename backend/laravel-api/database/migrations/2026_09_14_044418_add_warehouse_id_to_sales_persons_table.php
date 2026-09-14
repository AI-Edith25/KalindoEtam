<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** "Location/Area" on the Sales Person maintenance page — a Warehouse, not free text (see Customer's `area` column). Nullable: existing sales persons have none assigned. */
    public function up(): void
    {
        Schema::table('sales_persons', function (Blueprint $table) {
            $table->foreignUuid('warehouse_id')->nullable()->after('email')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_persons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
