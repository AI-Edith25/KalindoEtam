<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * User feedback right after shipping: real data already has customers
     * coded "C-0001" onward (not "CUST-"), and manual count-at-migration-time
     * wasn't a safe enough estimate of where they actually stop — current
     * codes run through roughly C-2104. Correcting the series set up by
     * 2026_09_11_000001: prefix -> "C-", current_number -> 2104 so the
     * next generated code is C-2105, well clear of every existing one.
     */
    public function up(): void
    {
        DB::table('naming_series')->where('document_type', 'customer')->update([
            'prefix' => 'C-',
            'current_number' => 2104,
        ]);
    }

    public function down(): void
    {
        DB::table('naming_series')->where('document_type', 'customer')->update([
            'prefix' => 'CUST-',
            'current_number' => 0,
        ]);
    }
};
