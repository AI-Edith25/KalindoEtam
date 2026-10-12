<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Services\PaymentAllocationService;
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
 * with a real Official Receipt already allocated against its AccountsReceivable row is NOT blocked
 * — business decision 2026-10-08, same shape as PurgeImportedOfficialReceiptsCommand: every
 * non-reversed PaymentAllocation is reversed first (PaymentAllocationService::reverse(), which
 * posts the offsetting journal leg and decrements the Official Receipt's own allocated_amount back
 * to unallocated), so the Receipt itself is untouched and keeps its full history, just no longer
 * applied to an invoice that's about to disappear. A Credit/Debit Note issued against the invoice
 * still blocks outright — a different, heavier kind of document this command doesn't attempt to
 * unwind. Blocked rows are reported, not force-deleted.
 *
 * Defaults to a dry run (reports counts only, via blockingReason() alone — no row is ever touched
 * or locked for a dry run). --commit processes invoices via chunkById with a progress bar, each in
 * its own short transaction: a single mega-transaction spanning the whole run would hold every row
 * it touches locked against live traffic for the run's entire duration, which is exactly what made
 * the companion PurgeImportedOfficialReceiptsCommand look hung on a large dataset (2026-10-08
 * incident) before it was fixed the same way.
 *
 * Also hard-deletes (--commit) whatever an EARLIER --commit run left merely soft-deleted — see
 * hardDeleteAlreadyTrashed()'s own docblock. A plain soft-delete never frees
 * source_document_number_normalized, which permanently blocked CustomerOutstandingArchiveImportService
 * from recreating the same unpaid bill once it was (correctly) classified skip_duplicate — found
 * 2026-10-12 via Customer C-1364 / SI/KE/03827/05/2026, present in the source Excel but missing
 * from both the Sales Invoice list and Outstanding Bills.
 */
class PurgeHistoricalSalesInvoiceImportCommand extends Command
{
    protected $signature = 'sales-invoice-import:purge {--commit : Actually delete; without this flag, only reports what would happen}';

    protected $description = 'Delete every historical-imported Sales Invoice (and its items, AR row, and import batch history) - reverses a real payment allocated against it first, skips only a credit/debit note against it.';

    public function __construct(protected PaymentAllocationService $paymentAllocationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $total = Invoice::query()->where('import_source_type', 'historical_invoice')->count();
        $batchCount = ImportBatch::query()->where('module', 'sales-invoice-history')->count();

        if (! $this->option('commit')) {
            return $this->dryRun($total, $batchCount);
        }

        // Captured before this run's own purge loop below trashes anything new -- only rows a
        // PRIOR --commit run left trashed are hard-deleted; a row this same run just soft-deleted
        // (e.g. the real-payment-allocation case, which must keep its reversed PaymentAllocation
        // row) is never touched by hardDeleteAlreadyTrashed().
        $alreadyTrashedIds = Invoice::onlyTrashed()->where('import_source_type', 'historical_invoice')->pluck('id')->all();

        $deleted = 0;
        $blocked = [];
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Invoice::query()->where('import_source_type', 'historical_invoice')->chunkById(200, function ($invoices) use (&$deleted, &$blocked, $bar) {
            foreach ($invoices as $invoice) {
                $reason = $this->blockingReason($invoice);

                if ($reason !== null) {
                    $blocked[] = "{$invoice->document_number}: {$reason}";
                    $bar->advance();

                    continue;
                }

                try {
                    DB::transaction(function () use ($invoice) {
                        $this->reverseAllocations($invoice);
                        $invoice->items()->delete();
                        $invoice->accountsReceivable()->delete();
                        $invoice->delete();
                    });
                    $deleted++;
                } catch (Throwable $e) {
                    $blocked[] = "{$invoice->document_number}: {$e->getMessage()}";
                }

                $bar->advance();
            }
        });

        $bar->finish();

        $batches = ImportBatch::query()->where('module', 'sales-invoice-history')->get();
        foreach ($batches as $batch) {
            $batch->delete();

            if ($batch->file_path) {
                Storage::disk($batch->disk)->delete($batch->file_path);
            }
            if ($batch->error_report_path) {
                Storage::disk($batch->disk)->delete($batch->error_report_path);
            }
        }

        [$hardDeleted, $hardBlocked] = $this->hardDeleteAlreadyTrashed($alreadyTrashedIds);

        $this->line('');
        $this->line('');
        $this->info("Invoices deleted: {$deleted}");
        $this->info('Import batches removed: '.count($batches));
        $this->info("Already-trashed invoices hard-deleted (freeing their document number for re-import): {$hardDeleted}");

        if ($blocked !== [] || $hardBlocked !== []) {
            $this->warn('Invoices skipped (something still references them — resolve manually, then re-run):');
            foreach ([...$blocked, ...$hardBlocked] as $reason) {
                $this->line("  - {$reason}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * A prior --commit run only soft-deletes (see handle()'s own DB::transaction above), which
     * never frees source_document_number_normalized -- that leaves CustomerOutstandingArchiveImportService
     * permanently unable to recreate the same unpaid bill (DocumentDuplicateChecker::reject() keeps
     * finding the trashed row). Business decision 2026-10-12: finish the job by hard-deleting
     * whatever an earlier run left trashed, so the unique index is actually freed.
     *
     * Bulk query-builder deletes, not a chunked Eloquent loop like the live-row pass above --
     * found 2026-10-12: a per-row loop (one SELECT + one DELETE each for AR, PaymentAllocation,
     * Invoice, plus two more for the CN/DN block check) over 16,185 already-dead rows is ~80,000
     * round trips, intolerably slow for what's otherwise a single cleanup sweep with nothing left
     * to react to per row (reverseAllocations() does not apply here -- PaymentAllocation rows on
     * an already-trashed invoice were reversed, never deleted, by the run that trashed it).
     * Batched by 1,000 ids instead: a handful of queries per batch, each batch its own transaction
     * (same reasoning as the live pass -- never one lock held for the whole run).
     *
     * PaymentAllocation force-deleted first -- restrictOnDelete on accounts_receivable_id would
     * otherwise block the AR's own delete -- its GL impact lives in the journal entry it posted,
     * not in this row, so nothing financial is lost. InvoiceItem rows are left to invoices.id's
     * cascadeOnDelete (migration create_invoice_items_table).
     *
     * @param  array<int, string>  $alreadyTrashedIds  Snapshot taken before this run's own purge loop, see handle().
     * @return array{0: int, 1: array<int, string>}
     */
    private function hardDeleteAlreadyTrashed(array $alreadyTrashedIds): array
    {
        if ($alreadyTrashedIds === []) {
            return [0, []];
        }

        $deleted = 0;
        $blocked = [];

        foreach (array_chunk($alreadyTrashedIds, 1000) as $idBatch) {
            $blockedIds = $this->blockedInvoiceIds($idBatch);

            foreach ($blockedIds as $id) {
                $blocked[] = "{$id}: has a Credit or Debit Note issued against it.";
            }

            $deletableIds = array_values(array_diff($idBatch, $blockedIds));

            if ($deletableIds === []) {
                continue;
            }

            DB::transaction(function () use ($deletableIds, &$deleted) {
                $arIds = DB::table('accounts_receivables')->whereIn('invoice_id', $deletableIds)->pluck('id');

                if ($arIds->isNotEmpty()) {
                    DB::table('payment_allocations')->whereIn('accounts_receivable_id', $arIds)->delete();
                    DB::table('accounts_receivables')->whereIn('invoice_id', $deletableIds)->delete();
                }

                $deleted += DB::table('invoices')->whereIn('id', $deletableIds)->delete();
            });
        }

        return [$deleted, $blocked];
    }

    /** Same rule as blockingReason(), bulk-checked for a whole id batch in two queries instead of
        one Eloquent model + two exists() queries per row. @return array<int, string> */
    private function blockedInvoiceIds(array $ids): array
    {
        return array_values(array_unique([
            ...DB::table('credit_notes')->whereIn('invoice_id', $ids)->pluck('invoice_id')->all(),
            ...DB::table('debit_notes')->whereIn('invoice_id', $ids)->pluck('invoice_id')->all(),
        ]));
    }

    /** Pure count, no mutation and no lock — blockingReason() alone is enough to classify every row without touching it. */
    private function dryRun(int $total, int $batchCount): int
    {
        $blockedCount = 0;

        Invoice::query()->where('import_source_type', 'historical_invoice')->chunkById(200, function ($invoices) use (&$blockedCount) {
            foreach ($invoices as $invoice) {
                if ($this->blockingReason($invoice) !== null) {
                    $blockedCount++;
                }
            }
        });

        $trashedIds = Invoice::onlyTrashed()->where('import_source_type', 'historical_invoice')->pluck('id')->all();
        $trashedBlockedCount = 0;

        foreach (array_chunk($trashedIds, 1000) as $idBatch) {
            $trashedBlockedCount += count($this->blockedInvoiceIds($idBatch));
        }

        $this->info("Historical-imported invoices found: {$total}");
        $this->info("  of which would be blocked (credit/debit note against them): {$blockedCount}");
        $this->info('  of which would be deleted (reversing any real payment allocated first): '.($total - $blockedCount));
        $this->info("Import batches that would be removed: {$batchCount}");
        $this->info('Already-trashed invoices that would be hard-deleted: '.(count($trashedIds) - $trashedBlockedCount));
        $this->info("  of which blocked instead (credit/debit note against them): {$trashedBlockedCount}");
        $this->warn('Dry run — no changes were made. Pass --commit to actually delete.');

        return self::SUCCESS;
    }

    private function blockingReason(Invoice $invoice): ?string
    {
        if (CreditNote::query()->where('invoice_id', $invoice->id)->exists()) {
            return 'has a Credit Note issued against it.';
        }

        if (DebitNote::query()->where('invoice_id', $invoice->id)->exists()) {
            return 'has a Debit Note issued against it.';
        }

        return null;
    }

    /** Reverses every non-reversed PaymentAllocation against this invoice's AR — the Official
        Receipt itself is never touched, only its application to this one invoice. No-op (and no
        query) for an invoice with no AR row at all. */
    private function reverseAllocations(Invoice $invoice): void
    {
        $arId = $invoice->accountsReceivable?->id;

        if ($arId === null) {
            return;
        }

        PaymentAllocation::query()->where('accounts_receivable_id', $arId)->where('is_reversed', false)
            ->get()->each(fn (PaymentAllocation $allocation) => $this->paymentAllocationService->reverse($allocation));
    }
}
