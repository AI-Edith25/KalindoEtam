<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Price Zone removal — see 2026_09_03_000004's docblock. Must drop before price_zones (FK). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_zone_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignUuid('price_zone_id')->nullable()->after('customer_name')
                ->constrained('price_zones')->nullOnDelete();
        });
    }
};
