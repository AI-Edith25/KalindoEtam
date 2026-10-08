<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\ImportBatch;
use App\Models\PaymentAllocation;
use App\Models\ReceiptEntry;
use App\Services\AccountingService;
use App\Services\PaymentAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Companion to PurgeHistoricalSalesInvoiceImportCommand — same 2026-10-08 decision to drop the
 * historical-import datasets in favour of the Customer Outstanding Bills snapshot flow, this time
 * for OfficialReceiptImportService's output (ReceiptEntry.source = 'import').
 *
 * Unlike the SI historical import, this one is NOT GL/AR-inert: OfficialReceiptImportService runs
 * every receipt through the real ReceiptEntryService::create()/submit() and
 * PaymentAllocationService::allocateBatch() (see its own docblock — "no historical-skip flag the
 * way Invoice has"), so each imported receipt has posted a real Dr Cash/Cr Unapplied Customer
 * Payments journal and, where it matched an open receivable, a real allocation that already
 * decremented that AccountsReceivable's paid_amount. Deleting the row alone would silently leave
 * those GL postings and AR balances wrong. So each receipt is unwound properly before it's
 * removed: every non-reversed PaymentAllocation goes through PaymentAllocationService::reverse()
 * (restores the AR, posts the offsetting journal leg — the same primitive a real correction would
 * use), then the receipt's own journal is reversed via AccountingService::reverseForDocument().
 * ReceiptEntry::cancel() can't be reused for this — it's a hard stub ("Reversal is not yet
 * implemented") pending a dedicated void workflow this command doesn't attempt to build; it only
 * replays the two lower-level primitives a future cancel() would also need.
 *
 * Every model touched here (ReceiptEntry, PaymentAllocation, AccountsReceivable) is soft-deleted,
 * so ->delete() never fires a real SQL DELETE — nothing here actually depends on cascadeOnDelete.
 * Both the receipt and its allocations are soft-deleted explicitly so a reversed-but-undeleted
 * allocation never outlives its parent in any payment-history list.
 *
 * Each receipt is unwound in its own short transaction so one failure never aborts the rest, and so
 * no lock is held any longer than one receipt's worth of work — with a large volume of receipts,
 * one mega-transaction spanning the whole run would hold every row it touches locked against live
 * traffic for the entire command's duration. Processed via chunkById with a progress bar rather
 * than get()->each() for the same reason: visible progress on a long run, bounded memory. Without
 * --commit this never calls reverse()/delete() at all — it only counts — so a dry run is fast and
 * takes no locks; the original version actually executed every reversal and rolled back at the end,
 * which is what made it look hung on a large dataset (2026-10-08 incident).
 */
class PurgeImportedOfficialReceiptsCommand extends Command
{
    protected $signature = 'official-receipt-import:purge {--commit : Actually delete; without this flag, only reports what would happen}';

    protected $description = 'Reverse and delete every imported Official Receipt (official-receipts module): unwinds its payment allocations and journal, then removes the receipt and its import batch history.';

    public function __construct(
        protected PaymentAllocationService $paymentAllocationService,
        protected AccountingService $accountingService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $total = ReceiptEntry::query()->where('source', 'import')->count();
        $batchCount = ImportBatch::query()->where('module', 'official-receipts')->count();

        if (! $this->option('commit')) {
            return $this->dryRun($total, $batchCount);
        }

        $deleted = 0;
        $blocked = [];
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        ReceiptEntry::query()->where('source', 'import')->chunkById(200, function ($entries) use (&$deleted, &$blocked, $bar) {
            foreach ($entries as $entry) {
                try {
                    DB::transaction(function () use ($entry) {
                        PaymentAllocation::query()->where('receipt_entry_id', $entry->id)->where('is_reversed', false)
                            ->get()->each(fn (PaymentAllocation $allocation) => $this->paymentAllocationService->reverse($allocation));

                        if ($entry->status === DocumentStatus::SUBMITTED) {
                            $this->accountingService->reverseForDocument($entry);
                        }

                        PaymentAllocation::query()->where('receipt_entry_id', $entry->id)->delete();
                        $entry->delete();
                    });
                    $deleted++;
                } catch (Throwable $e) {
                    $blocked[] = "{$entry->reference_number}: {$e->getMessage()}";
                }

                $bar->advance();
            }
        });

        $bar->finish();

        $batches = ImportBatch::query()->where('module', 'official-receipts')->get();
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
        $this->info("Official Receipts deleted: {$deleted}");
        $this->info('Import batches removed: '.count($batches));

        if ($blocked !== []) {
            $this->warn('Receipts skipped (reversal failed — resolve manually, then re-run):');
            foreach ($blocked as $reason) {
                $this->line("  - {$reason}");
            }
        }

        return self::SUCCESS;
    }

    /** Pure count, no mutation and no lock — see this class's own docblock on why the old dry run actually executed every reversal. */
    private function dryRun(int $total, int $batchCount): int
    {
        $allocated = ReceiptEntry::query()->where('source', 'import')->where('allocated_amount', '>', 0)->count();

        $this->info("Imported Official Receipts found: {$total}");
        $this->info("  of which have at least one payment allocation to reverse: {$allocated}");
        $this->info("Import batches that would be removed: {$batchCount}");
        $this->warn('Dry run — no changes were made. Pass --commit to actually delete.');

        return self::SUCCESS;
    }
}
