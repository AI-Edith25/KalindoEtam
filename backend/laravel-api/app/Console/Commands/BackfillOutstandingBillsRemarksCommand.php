<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time correction for Invoice rows CustomerOutstandingArchiveImportService created before
 * 2026-10-13 with remarks "Diimpor dari Customer Outstanding Bills." — new imports now leave
 * remarks null (see that service's own createInvoicesFromLines()). Idempotent: only touches rows
 * still carrying that exact text, scoped to import_source_type = 'outstanding_bills_archive' so
 * no other invoice's remarks is ever touched.
 */
class BackfillOutstandingBillsRemarksCommand extends Command
{
    protected $signature = 'sales-invoice:backfill-outstanding-bills-remarks {--dry-run : Compute and report without saving any changes}';

    protected $description = 'Clear the old "Diimpor dari Customer Outstanding Bills." remarks from Outstanding Bills import Invoices.';

    private const OLD_REMARKS = 'Diimpor dari Customer Outstanding Bills.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        $updated = Invoice::query()
            ->where('import_source_type', 'outstanding_bills_archive')
            ->where('remarks', self::OLD_REMARKS)
            ->update(['remarks' => null]);

        if ($dryRun) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->info("Invoices updated: {$updated}");

        return self::SUCCESS;
    }
}
