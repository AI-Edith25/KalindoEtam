<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Matches Invoice's own format move (2026_08_13_030137_update_invoice_naming_series_format.php):
 * Sales Order and Delivery move to SO/KE/#####/MM/YYYY and DO/KE/#####/MM/YYYY. MM/YYYY tags
 * the generation date only, not a reset boundary, so current_number restarts at 0 (next number:
 * 00001) rather than carrying over the old SO-/DN- counter. Already-issued SO-/DN- document_numbers
 * are historical data and are not renumbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('naming_series')->where('document_type', 'sales')->update([
            'prefix' => 'SO/KE/',
            'suffix' => '/{MM}/{YYYY}',
            'current_number' => 0,
        ]);

        DB::table('naming_series')->where('document_type', 'delivery')->update([
            'prefix' => 'DO/KE/',
            'suffix' => '/{MM}/{YYYY}',
            'current_number' => 0,
        ]);
    }

    public function down(): void
    {
        DB::table('naming_series')->where('document_type', 'sales')->update([
            'prefix' => 'SO-',
            'suffix' => null,
        ]);

        DB::table('naming_series')->where('document_type', 'delivery')->update([
            'prefix' => 'DN-',
            'suffix' => null,
        ]);
    }
};
