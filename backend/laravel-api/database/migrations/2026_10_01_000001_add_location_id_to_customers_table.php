<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the free-text "Location / Area" input on the Customer form with a dropdown+search
 * against the Warehouse master (labelled "Location" throughout this app — see
 * WarehouseImportTemplate's own "Warehouses (Area)" label and the /master/warehouses "Locations"
 * page). The old `area` string column is kept, not dropped — it still holds whatever legacy free
 * text existing customers have, and CustomerListingExport's "AreaCode" column (a SkyBiz round-trip
 * template, a different concept from this UI field) still reads it; this migration is purely
 * additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUuid('location_id')->nullable()->after('area')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
