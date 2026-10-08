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

        $this->line('');
        $this->line('');
        $this->info("Invoices deleted: {$deleted}");
        $this->info('Import batches removed: '.count($batches));

        if ($blocked !== []) {
            $this->warn('Invoices skipped (something still references them — resolve manually, then re-run):');
            foreach ($blocked as $reason) {
                $this->line("  - {$reason}");
            }
        }

        return self::SUCCESS;
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

        $this->info("Historical-imported invoices found: {$total}");
        $this->info("  of which would be blocked (credit/debit note against them): {$blockedCount}");
        $this->info('  of which would be deleted (reversing any real payment allocated first): '.($total - $blockedCount));
        $this->info("Import batches that would be removed: {$batchCount}");
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
