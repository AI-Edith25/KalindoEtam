<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Singleton settings row (Administration > Purchase Settings) — one
     * value today (weight_over_receipt_tolerance_percent), same "single
     * scalar config" shape as this could grow into, without inventing a
     * generic key-value settings table nothing else in this codebase uses.
     * Seeds its one row here so PurchaseSettingRepository::current() never
     * has to handle "no row yet".
     */
    public function up(): void
    {
        Schema::create('purchase_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null or 0 = no upper bound on Weight-category over-receipt (still gets the
            // informational warning, never blocked/needs confirmation).
            $table->decimal('weight_over_receipt_tolerance_percent', 5, 2)->nullable()->default(10);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('purchase_settings')->insert([
            'id' => (string) Str::uuid(),
            'weight_over_receipt_tolerance_percent' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_settings');
    }
};
