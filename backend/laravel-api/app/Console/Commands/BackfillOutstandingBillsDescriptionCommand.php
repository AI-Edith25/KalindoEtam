<?php

namespace App\Console\Commands;

use App\Models\InvoiceItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time correction for InvoiceItem rows CustomerOutstandingArchiveImportService created before
 * 2026-10-12 with item_name "Outstanding Balance — {ref_no}" — the printed Description now reads
 * just the ref_no (see that service's own createInvoicesFromLines()). Idempotent: only touches
 * rows still carrying the old prefix, scoped to import_source_type = 'outstanding_bills_archive'
 * so no other invoice's line is ever touched.
 */
class BackfillOutstandingBillsDescriptionCommand extends Command
{
    protected $signature = 'sales-invoice:backfill-outstanding-bills-description {--dry-run : Compute and report without saving any changes}';

    protected $description = 'Strip the old "Outstanding Balance —" prefix from Outstanding Bills import InvoiceItem lines, leaving just the ref_no.';

    private const PREFIX = 'Outstanding Balance — ';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        $updated = 0;

        InvoiceItem::query()
            ->whereHas('invoice', fn ($query) => $query->where('import_source_type', 'outstanding_bills_archive'))
            ->where('item_name', 'like', self::PREFIX.'%')
            ->chunkById(500, function ($items) use (&$updated) {
                foreach ($items as $item) {
                    $item->item_name = mb_substr($item->item_name, mb_strlen(self::PREFIX));
                    $item->save();
                    $updated++;
                }
            });

        if ($dryRun) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->info("Invoice lines updated: {$updated}");

        return self::SUCCESS;
    }
}
