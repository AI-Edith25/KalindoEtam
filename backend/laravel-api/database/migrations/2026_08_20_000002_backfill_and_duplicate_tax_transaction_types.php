<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step 2 of 3. Every pre-existing Tax row (created before transaction_type
 * existed) needs a side. "PPN 0% (Ekspor)" is Sales-only by nature (export
 * sales). Everything else is duplicated into a Purchase-tagged copy (keeps
 * the original row's id — no FK on any existing document needs updating)
 * and a new Sales-tagged copy, both starting at the same rate/calculation_mode
 * — accounting can freely rename code/name/rate afterward per row, this
 * migration only picks a starting point.
 *
 * FLAGGED: this default mapping (which rows are Sales-only vs duplicated
 * both ways) is this session's proposal, not a confirmed accounting
 * decision — see the sprint ticket's own note to confirm with accounting
 * before relying on it in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        $salesOnlyNames = ['PPN 0% (Ekspor)'];

        DB::transaction(function () use ($salesOnlyNames) {
            $taxes = DB::table('taxes')->whereNull('transaction_type')->get();

            foreach ($taxes as $tax) {
                if (in_array($tax->name, $salesOnlyNames, true)) {
                    DB::table('taxes')->where('id', $tax->id)->update(['transaction_type' => 'sales']);

                    continue;
                }

                DB::table('taxes')->where('id', $tax->id)->update([
                    'transaction_type' => 'purchase',
                    'code' => $tax->code.'-P',
                ]);

                DB::table('taxes')->insert([
                    'id' => (string) Str::uuid(),
                    'code' => $tax->code.'-S',
                    'name' => $tax->name,
                    'type' => $tax->type,
                    'rate' => $tax->rate,
                    'calculation_mode' => $tax->calculation_mode,
                    'transaction_type' => 'sales',
                    'is_active' => $tax->is_active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Irreversible data transformation (row duplication) — intentionally a no-op.
    }
};
