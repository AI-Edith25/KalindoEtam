<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            // Kept alongside the legacy free-text `fleet`/`driver` columns rather than replacing
            // them: fleet_id is the new reliable link the commission report resolves rates
            // against, while `fleet` (plat_number text) stays as-is for existing screens/reports
            // that already read it. The frontend writes both on save.
            $table->foreignUuid('fleet_id')->nullable()->after('driver')->constrained('fleets')->nullOnDelete();
            $table->boolean('include_in_commission_recap')->default(true)->after('fleet_id');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fleet_id');
            $table->dropColumn('include_in_commission_recap');
        });
    }
};
