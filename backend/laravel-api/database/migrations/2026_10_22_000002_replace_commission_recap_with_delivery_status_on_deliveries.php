<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the include_in_commission_recap boolean with delivery_status (App\Enums\DeliveryMethod)
 * — "Status Pengantaran" now has 4 values with different commission consequences, not just an
 * on/off recap flag. Data is still tiny (feature only shipped 2026-10-21), so a single CASE-based
 * backfill covers every existing row: TRUE -> antar_dengan_buruh, FALSE -> antar_tanpa_buruh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('delivery_status')->nullable()->after('include_in_commission_recap');
        });

        DB::statement("UPDATE deliveries SET delivery_status = CASE WHEN include_in_commission_recap THEN 'antar_dengan_buruh' ELSE 'antar_tanpa_buruh' END");

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('include_in_commission_recap');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->boolean('include_in_commission_recap')->default(true)->after('delivery_status');
        });

        DB::statement("UPDATE deliveries SET include_in_commission_recap = CASE WHEN delivery_status = 'antar_dengan_buruh' THEN 1 ELSE 0 END");

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('delivery_status');
        });
    }
};
