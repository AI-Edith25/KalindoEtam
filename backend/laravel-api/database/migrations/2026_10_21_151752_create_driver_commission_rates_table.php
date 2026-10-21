<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_commission_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fleet_id')->constrained('fleets')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->decimal('rate_per_zak', 15, 2);
            $table->date('effective_date');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Not unique — see DriverCommissionRateService: adding a new rate for the same
            // fleet+item is always an insert, never an update, so history can carry several
            // rows per pair. This index just makes the "latest effective_date <= date" lookup fast.
            $table->index(['fleet_id', 'item_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_commission_rates');
    }
};
