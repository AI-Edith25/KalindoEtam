<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: historical Customer import wrote customer_code values (C-0001..C-2111 range) directly
 * without ever advancing naming_series.current_number for document_type='customer' (import never
 * goes through DocumentNumberGeneratorService::generate() — it writes real codes verbatim). The
 * counter was left wherever it was before that import, so "New Customer" kept suggesting/
 * generating codes that collided with already-imported ones. Bumped to 2111 so the very next
 * generated code is C-2112 — past every known imported code. Guarded (`< 2111`) so this never
 * moves the counter backward if it has legitimately advanced past this point already; reruns
 * match 0 rows once fixed, same idempotent posture as 2026_09_23_000001's own credit_limit fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::table('naming_series')
            ->where('document_type', 'customer')
            ->where('current_number', '<', 2111)
            ->update(['current_number' => 2111]);

        fwrite(STDOUT, "  Bumped customer naming series current_number to 2111 on {$affected} row(s) — next generated code is C-2112.\n");
    }

    /** Best-effort revert only — the exact prior current_number isn't recorded, so this is a no-op; rolling back a counter bump safely isn't possible once new codes may have been generated past it. */
    public function down(): void {}
};
