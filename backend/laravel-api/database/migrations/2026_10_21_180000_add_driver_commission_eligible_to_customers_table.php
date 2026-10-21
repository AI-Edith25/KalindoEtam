<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Gates whether the Delivery form shows the Fleet/Driver/commission-recap fields at
            // all for this customer — off by default so existing customers keep the plain DO form
            // they already have until explicitly opted in.
            $table->boolean('driver_commission_eligible')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('driver_commission_eligible');
        });
    }
};
