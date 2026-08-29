<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Undoes 2026_08_29_000001_create_invoice_print_settings_table — that
     * feature (company-wide Header/Footer/Layout/Columns print settings) was
     * cancelled after already being deployed, so a forward migration is
     * needed to actually drop the table on servers that ran the original
     * one; deleting that migration file wouldn't touch an already-migrated
     * database.
     */
    public function up(): void
    {
        Schema::dropIfExists('invoice_print_settings');
    }

    public function down(): void
    {
        Schema::create('invoice_print_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
    }
};
