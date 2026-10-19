<?php

use App\Enums\ReceivableCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: 2026_10_10_000001_add_receivable_category_to_customers_table defaulted every
 * existing customer to 'C' (deliberate, zero behavior change at the time) — but the PK/PL
 * backfill from the legacy SkyBiz naming pattern was never actually run, so the "Kasbon /
 * Pinjaman (Piutang)" Payment Voucher picker (filters receivable_category IN ('PK','PL'))
 * has always returned zero customers in production despite ~77 of them clearly being PK/PL
 * by their own code/name (user-reported 2026-10-19, confirmed against live production data:
 * all 2,161 customers were 'C', 0 were PK/PL).
 *
 * Rule (order matters — name checked before code prefix, confirmed with user):
 *   1. customer_name contains "karyawan" (case-insensitive)        -> PK
 *   2. else customer_code starts with "PK" (any/no separator)      -> PK
 *   3. else customer_code starts with "PL" (any/no separator)      -> PL
 *   4. else                                                        -> C (no change from default)
 *
 * One confirmed exception (2026-10-19, user decision): PL-0016 "PLL/EKO ANDRI ASTUSTI-KARYAWAN"
 * contains "karyawan" (rule 1 would say PK) but is kept PL per its code prefix — user chose not
 * to apply rule 1 here, likely a duplicate/misspelled entry of PK/005 "EKO ANDRI ASTUTI", left
 * as-is rather than guessed at further.
 *
 * Idempotent: a customer whose receivable_category already matches its computed target is
 * skipped (0 rows affected on a rerun). Loops in PHP rather than a single UPDATE ... CASE: the
 * "contains karyawan" / "starts with PK or PL, with or without a separator" matching needs
 * pattern logic that isn't portably expressible as one portable SQL statement across
 * sqlite (tests) and MySQL (production).
 */
return new class extends Migration
{
    protected const KEPT_DESPITE_NAME_MATCH = ['PL-0016'];

    public function up(): void
    {
        $customers = DB::table('customers')->select('id', 'customer_code', 'customer_name', 'receivable_category')->get();

        $counts = ['PK' => 0, 'PL' => 0];

        foreach ($customers as $customer) {
            $target = $this->targetCategory($customer->customer_code ?? '', $customer->customer_name ?? '');

            if ($target === $customer->receivable_category) {
                continue;
            }

            DB::table('customers')->where('id', $customer->id)->update(['receivable_category' => $target]);
            $counts[$target] = ($counts[$target] ?? 0) + 1;
        }

        fwrite(STDOUT, "  Backfilled receivable_category: {$counts['PK']} customer(s) to PK, {$counts['PL']} to PL.\n");
    }

    protected function targetCategory(string $code, string $name): string
    {
        if (in_array($code, self::KEPT_DESPITE_NAME_MATCH, true)) {
            return ReceivableCategory::OTHER->value;
        }

        if (stripos($name, 'karyawan') !== false) {
            return ReceivableCategory::EMPLOYEE->value;
        }

        if (preg_match('/^PK[\/\-]?/i', $code)) {
            return ReceivableCategory::EMPLOYEE->value;
        }

        if (preg_match('/^PL[\/\-]?/i', $code)) {
            return ReceivableCategory::OTHER->value;
        }

        return ReceivableCategory::TRADE->value;
    }

    /** Best-effort revert: sets every currently PK/PL customer back to 'C', the pre-this-migration state for all of them (no customer was PK/PL before this ran). */
    public function down(): void
    {
        DB::table('customers')->whereIn('receivable_category', ['PK', 'PL'])->update(['receivable_category' => 'C']);
    }
};
