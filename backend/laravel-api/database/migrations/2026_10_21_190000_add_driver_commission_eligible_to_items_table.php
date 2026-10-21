<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Gates whether the Delivery form shows the Fleet/Driver/commission-recap fields —
            // shown when the DO contains at least one line item flagged here. Off by default so
            // existing items keep the plain DO form until explicitly registered as commissionable.
            $table->boolean('driver_commission_eligible')->default(false)->after('sync_to_main_wh');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('driver_commission_eligible');
        });
    }
};
