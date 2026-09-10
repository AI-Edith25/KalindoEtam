<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Customer Code auto-generation (CUST-0001, CUST-0002, ...) — same
     * DocumentNumberGeneratorService/NamingSeries machinery every
     * transactional document already uses (see DocumentEngineSeeder),
     * now called from CustomerService::create() too.
     *
     * current_number seeds from the existing customer count, not 0 — this
     * table already has manually-typed, inconsistently-formatted codes
     * (CustomerFormDrawer's old placeholder was "e.g. CUS001"), so starting
     * the new CUST-#### sequence after all of them makes a collision with
     * an old code extremely unlikely. customers.customer_code is still
     * UNIQUE regardless, so a collision would fail loudly on insert rather
     * than silently overwrite anything.
     */
    public function up(): void
    {
        $exists = DB::table('naming_series')->where('document_type', 'customer')->exists();

        if ($exists) {
            return;
        }

        $customerCount = (int) DB::table('customers')->count();

        DB::table('naming_series')->insert([
            'id' => (string) Str::uuid(),
            'module' => 'master',
            'document_type' => 'customer',
            'prefix' => 'CUST-',
            'suffix' => null,
            'digit_length' => 4,
            'current_number' => $customerCount,
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('naming_series')->where('document_type', 'customer')->delete();
    }
};
