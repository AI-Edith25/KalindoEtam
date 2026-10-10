<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * PK/PL get their own independent NamingSeries, same machinery as 'customer' (C-) —
     * see DocumentNumberGeneratorService. current_number is computed from whatever PK-/PL-
     * coded customers already exist (not hardcoded to 0) — same "continue after real data,
     * don't guess" approach 2026_09_11_000002/2026_10_01_000002 used for the 'customer' series,
     * computed in PHP (not raw SQL) so it behaves identically on MySQL prod and sqlite tests.
     */
    public function up(): void
    {
        foreach (['customer_pk' => 'PK-', 'customer_pl' => 'PL-'] as $documentType => $prefix) {
            if (DB::table('naming_series')->where('document_type', $documentType)->exists()) {
                continue;
            }

            $lastNumber = DB::table('customers')
                ->where('customer_code', 'like', "{$prefix}%")
                ->pluck('customer_code')
                ->map(function (string $code) use ($prefix) {
                    $suffix = substr($code, strlen($prefix));

                    return ctype_digit($suffix) ? (int) $suffix : 0;
                })
                ->max() ?? 0;

            DB::table('naming_series')->insert([
                'id' => (string) Str::uuid(),
                'module' => 'master',
                'document_type' => $documentType,
                'prefix' => $prefix,
                'suffix' => null,
                'digit_length' => 4,
                'current_number' => $lastNumber,
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('naming_series')->whereIn('document_type', ['customer_pk', 'customer_pl'])->delete();
    }
};
