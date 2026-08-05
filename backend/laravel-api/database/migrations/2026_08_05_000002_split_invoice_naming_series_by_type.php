<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Sprint 2 (Invoice Numbering): Goods and Transportation invoices now
     * number independently. The existing 'invoice' series becomes the
     * Goods series — renamed in place so current_number (and every
     * already-generated Invoice document_number) carries over exactly as
     * it was, no counter reset, no data loss. Transportation gets a new,
     * separate series seeded with its own prefix.
     */
    public function up(): void
    {
        DB::table('naming_series')->where('document_type', 'invoice')->update(['document_type' => 'invoice_goods']);

        $exists = DB::table('naming_series')->where('document_type', 'invoice_transportation')->exists();

        if (! $exists) {
            DB::table('naming_series')->insert([
                'id' => (string) Str::uuid(),
                'module' => 'invoice',
                'document_type' => 'invoice_transportation',
                'prefix' => 'ANG-',
                'suffix' => null,
                'digit_length' => 5,
                'current_number' => 0,
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('naming_series')->where('document_type', 'invoice_transportation')->delete();
        DB::table('naming_series')->where('document_type', 'invoice_goods')->update(['document_type' => 'invoice']);
    }
};
