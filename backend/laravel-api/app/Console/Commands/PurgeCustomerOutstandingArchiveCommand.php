<?php

namespace App\Console\Commands;

use App\Models\CustomerOutstandingSnapshot;
use App\Models\CustomerOutstandingSnapshotLine;
use Illuminate\Console\Command;

/**
 * Drops the "Piutang Customer (Arsip Import)" archive entirely — business decision 2026-10-08,
 * same reasoning as the SI/Official Receipt purges: CustomerOutstandingArchiveService's own
 * docblock already says this data is "no connection to the live Sales/Invoice/AR module", its one
 * functional consumer (BackfillHistoricalInvoiceAccountsReceivableCommand) already ran against
 * invoices that are now themselves deleted, and a live equivalent (Customer Outstanding Bills
 * (Live), computed straight from AccountsReceivable) already replaces it.
 *
 * Unlike the other two purges, nothing here posts GL or touches AR — these tables have no FK to
 * any live module (by design, see their own migrations) and aren't soft-deleted, so a single bulk
 * delete on the parent is correct and sufficient: customer_outstanding_snapshot_lines.snapshot_id
 * is a real cascadeOnDelete FK. No savepoints/chunking needed for the same reason the other two
 * purges did — there's no per-row business logic here to isolate failures from.
 */
class PurgeCustomerOutstandingArchiveCommand extends Command
{
    protected $signature = 'customer-outstanding-archive:purge {--commit : Actually delete; without this flag, only reports what would happen}';

    protected $description = 'Delete every Customer Outstanding Bills archive snapshot and its lines.';

    public function handle(): int
    {
        $snapshotCount = CustomerOutstandingSnapshot::query()->count();
        $lineCount = CustomerOutstandingSnapshotLine::query()->count();

        if (! $this->option('commit')) {
            $this->info("Snapshots found: {$snapshotCount}");
            $this->info("Snapshot lines found: {$lineCount}");
            $this->warn('Dry run — no changes were made. Pass --commit to actually delete.');

            return self::SUCCESS;
        }

        CustomerOutstandingSnapshot::query()->delete();

        $this->info("Snapshots deleted: {$snapshotCount}");
        $this->info("Snapshot lines deleted: {$lineCount}");

        return self::SUCCESS;
    }
}
