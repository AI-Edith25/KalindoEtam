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
 * Each receipt is unwound inside its own savepoint so one failure never aborts the rest. Defaults
 * to a dry run; pass --commit to actually apply it.
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
        $commit = (bool) $this->option('commit');

        DB::beginTransaction();

        $deleted = [];
        $blocked = [];

        ReceiptEntry::query()->where('source', 'import')->get()->each(function (ReceiptEntry $entry) use (&$deleted, &$blocked) {
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
                $deleted[] = $entry->reference_number ?? $entry->document_number;
            } catch (Throwable $e) {
                $blocked[] = "{$entry->reference_number}: {$e->getMessage()}";
            }
        });

        $batches = ImportBatch::query()->where('module', 'official-receipts')->get();
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
        $this->info('Official Receipts deleted: '.count($deleted));
        $this->info('Import batches removed: '.count($batches));

        if ($blocked !== []) {
            $this->warn('Receipts skipped (reversal failed — resolve manually, then re-run):');
            foreach ($blocked as $reason) {
                $this->line("  - {$reason}");
            }
        }

        return self::SUCCESS;
    }
}
