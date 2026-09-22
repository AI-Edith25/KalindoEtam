<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: the original customer import wrote a literal 0 into credit_limit
 * for every row instead of leaving unset limits blank, so every customer read
 * as "credit limit Rp 0" and every Sales Order tripped the over-limit block.
 * NULL already means "no limit" throughout the app (see
 * 2026_08_07_084519_add_credit_limit_to_customers_table.php); this just
 * corrects the bad data. Idempotent: reruns match 0 rows once fixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::table('customers')->where('credit_limit', 0)->update(['credit_limit' => null]);

        fwrite(STDOUT, "  Nulled out credit_limit for {$affected} customer(s) that had a literal 0.\n");
    }

    /**
     * Best-effort revert: sets currently-null rows back to 0. Can't distinguish
     * "was 0 before this migration" from a customer legitimately set to No
     * Limit afterward — acceptable for a one-time data-fix rollback.
     */
    public function down(): void
    {
        DB::table('customers')->whereNull('credit_limit')->update(['credit_limit' => 0]);
    }
};
