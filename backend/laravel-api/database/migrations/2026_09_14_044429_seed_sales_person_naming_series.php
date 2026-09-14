<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Sales Person Code auto-generation (SP-0001, SP-0002, ...) — same
     * DocumentNumberGeneratorService/NamingSeries machinery Customer Code
     * uses (see 2026_09_11_000001_seed_customer_naming_series.php).
     *
     * current_number seeds from the existing sales_persons count, not 0 —
     * this table already has manually-typed codes, so starting the new
     * SP-#### sequence after all of them makes a collision with an old
     * code extremely unlikely. sales_persons.code is still UNIQUE
     * regardless, so a collision would fail loudly on insert rather than
     * silently overwrite anything.
     */
    public function up(): void
    {
        $exists = DB::table('naming_series')->where('document_type', 'sales_person')->exists();

        if ($exists) {
            return;
        }

        $salesPersonCount = (int) DB::table('sales_persons')->count();

        DB::table('naming_series')->insert([
            'id' => (string) Str::uuid(),
            'module' => 'master',
            'document_type' => 'sales_person',
            'prefix' => 'SP-',
            'suffix' => null,
            'digit_length' => 4,
            'current_number' => $salesPersonCount,
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('naming_series')->where('document_type', 'sales_person')->delete();
    }
};
