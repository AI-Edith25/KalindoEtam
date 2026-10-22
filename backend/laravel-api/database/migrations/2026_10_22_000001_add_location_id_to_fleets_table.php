<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mirrors Customer's own location_id addition — see that migration's comment. Nullable: existing fleets have none yet. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->foreignUuid('location_id')->nullable()->after('default_driver_name')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fleets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
