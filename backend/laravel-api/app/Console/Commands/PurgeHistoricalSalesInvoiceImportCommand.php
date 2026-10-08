<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One-time reversal of the Sales Invoice historical import (import_source_type =
 * 'historical_invoice', see SalesInvoiceImportService). Business decision 2026-10-08: that whole
 * dataset is being dropped in favour of importing only *unpaid* invoices via the separate Customer
 * Outstanding Bills snapshot flow (CustomerOutstandingArchiveController) — the old system's full SI
 * history will be kept as an offline archive instead of living in this app.
 *
 * Each invoice is deleted inside its own savepoint so one blocked row never aborts the rest. A row
 * is blocked when something real references it that this command deliberately refuses to touch: a
 * real Official Receipt already allocated against its AccountsReceivable row (see
 * [[project_erp_sales_invoice_ar_backfill]]), or a Credit/Debit Note issued against it. Checked
 * explicitly up front rather than only relying on the matching restrictOnDelete FKs — MySQL
 * (production) enforces those, but this is deliberately not the only line of defense. Blocked rows
 * are reported, not force-deleted: unwinding a real payment allocation is the operator's call, not
 * this command's.
 *
 * Defaults to a dry run (reports counts only); pass --commit to actually delete. The whole run is
 * one transaction, so a dry run costs nothing and a committed run is all-or-nothing for the rows
 * that weren't individually blocked.
 */
class PurgeHistoricalSalesInvoiceImportCommand extends Command
{
    protected $signature = 'sales-invoice-import:purge {--commit : Actually delete; without this flag, only reports what would happen}';

    protected $description = 'Delete every historical-imported Sales Invoice (and its items, AR row, and import batch history), skipping any that already have a real payment or credit/debit note against them.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        DB::beginTransaction();

        $deleted = [];
        $blocked = [];

        Invoice::query()->where('import_source_type', 'historical_invoice')->get()->each(function (Invoice $invoice) use (&$deleted, &$blocked) {
            $reason = $this->blockingReason($invoice);

            if ($reason !== null) {
                $blocked[] = "{$invoice->document_number}: {$reason}";

                return;
            }

            try {
                DB::transaction(function () use ($invoice) {
                    $invoice->items()->delete();
                    $invoice->accountsReceivable()->delete();
                    $invoice->delete();
                });
                $deleted[] = $invoice->document_number;
            } catch (Throwable $e) {
                $blocked[] = "{$invoice->document_number}: {$e->getMessage()}";
            }
        });

        $batches = ImportBatch::query()->where('module', 'sales-invoice-history')->get();
        foreach ($batches as $batch) {
            $batch->delete();
        }

        if (! $commit) {
            DB::rollBack();
            $this->warn('Dry run — no changes were saved. Pass --commit to actually delete.');
        } else {
            DB::commit();

            foreach ($batches as $batch) {
                if ($batch->file_path) {
                    Storage::disk($batch->disk)->delete($batch->file_path);
                }
                if ($batch->error_report_path) {
                    Storage::disk($batch->disk)->delete($batch->error_report_path);
                }
            }
        }

        $this->line('');
        $this->info('Invoices deleted: '.count($deleted));
        $this->info('Import batches removed: '.count($batches));

        if ($blocked !== []) {
            $this->warn('Invoices skipped (something still references them — resolve manually, then re-run):');
            foreach ($blocked as $reason) {
                $this->line("  - {$reason}");
            }
        }

        return self::SUCCESS;
    }

    private function blockingReason(Invoice $invoice): ?string
    {
        $arId = $invoice->accountsReceivable?->id;

        if ($arId !== null && PaymentAllocation::query()->where('accounts_receivable_id', $arId)->exists()) {
            return 'has a real payment allocated against it — reverse that Official Receipt first if it must go.';
        }

        if (CreditNote::query()->where('invoice_id', $invoice->id)->exists()) {
            return 'has a Credit Note issued against it.';
        }

        if (DebitNote::query()->where('invoice_id', $invoice->id)->exists()) {
            return 'has a Debit Note issued against it.';
        }

        return null;
    }
}
