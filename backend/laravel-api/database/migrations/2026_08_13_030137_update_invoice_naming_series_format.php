<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * UAT review (2026-08-12): Invoice numbers move to SI/KE/#####/MM/YYYY
     * (Goods) and TR/KE/#####/MM/YYYY (Transportation) — MM/YYYY is a
     * generation-date tag only, not a reset boundary, so current_number
     * restarts at 0 (next number: 00001) rather than carrying over the old
     * INV-/ANG- counter. Already-issued INV-/ANG- document_numbers are
     * historical data and are not renumbered.
     */
    public function up(): void
    {
        DB::table('naming_series')->where('document_type', 'invoice_goods')->update([
            'prefix' => 'SI/KE/',
            'suffix' => '/{MM}/{YYYY}',
            'current_number' => 0,
        ]);

        DB::table('naming_series')->where('document_type', 'invoice_transportation')->update([
            'prefix' => 'TR/KE/',
            'suffix' => '/{MM}/{YYYY}',
            'current_number' => 0,
        ]);
    }

    public function down(): void
    {
        DB::table('naming_series')->where('document_type', 'invoice_goods')->update([
            'prefix' => 'INV-',
            'suffix' => null,
        ]);

        DB::table('naming_series')->where('document_type', 'invoice_transportation')->update([
            'prefix' => 'ANG-',
            'suffix' => null,
        ]);
    }
};
