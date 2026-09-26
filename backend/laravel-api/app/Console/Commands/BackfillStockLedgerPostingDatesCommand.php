<?php

namespace App\Console\Commands;

use App\Enums\StockVoucherType;
use App\Models\CreditNote;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\IssueStock;
use App\Models\OpeningStock;
use App\Models\PurchaseReturn;
use App\Models\ReceiptStock;
use App\Models\StockAdjustment;
use App\Models\StockIn;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One-time historical correction: every stock_ledgers row written before the "post at the
 * source document's own date" fix shipped has posting_datetime set to whatever now() was at
 * submit time instead of the document's own business date (receipt_date, delivery_date, ...) —
 * see GoodsReceiptService/DeliveryService/etc.'s stockLedgerService->record() calls. This walks
 * every voucher type, looks up its source document's own date column, and corrects any row
 * that still disagrees with it.
 *
 * Idempotent: a row already matching its source document's date is left untouched (and not
 * counted), so re-running after new documents have shipped through the fixed code, or after a
 * partial prior run, only touches what's still wrong. A row whose source document no longer
 * exists (hard-deleted) is skipped, not guessed at.
 */
class BackfillStockLedgerPostingDatesCommand extends Command
{
    protected $signature = 'stock-ledger:backfill-posting-dates {--dry-run : Compute and report without saving any changes}';

    protected $description = "Correct historical stock_ledgers.posting_datetime to match each source document's own date instead of its real submit time.";

    /** @var array<string, array{0: class-string<Model>, 1: string}> voucher_type => [Model class, date column] */
    private const SOURCES = [
        'goods_receipt' => [GoodsReceipt::class, 'receipt_date'],
        'delivery' => [Delivery::class, 'delivery_date'],
        'issue_stock' => [IssueStock::class, 'issue_date'],
        'receipt_stock' => [ReceiptStock::class, 'receipt_date'],
        'purchase_return' => [PurchaseReturn::class, 'return_date'],
        'stock_transfer' => [StockTransfer::class, 'transfer_date'],
        'credit_note' => [CreditNote::class, 'credit_note_date'],
        'stock_in' => [StockIn::class, 'date_in'],
        'opening_stock' => [OpeningStock::class, 'cutoff_date'],
        'stock_adjustment' => [StockAdjustment::class, 'adjustment_date'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        $totalCorrected = 0;
        $totalSkippedMissingSource = 0;

        foreach (self::SOURCES as $voucherType => [$modelClass, $dateColumn]) {
            $this->info(StockVoucherType::from($voucherType)->name.'...');

            $sourceDates = $modelClass::query()->pluck($dateColumn, 'id');

            StockLedger::query()
                ->where('voucher_type', $voucherType)
                ->chunkById(500, function ($ledgers) use ($sourceDates, &$totalCorrected, &$totalSkippedMissingSource) {
                    foreach ($ledgers as $ledger) {
                        $correctDate = $sourceDates[$ledger->voucher_id] ?? null;

                        if ($correctDate === null) {
                            $totalSkippedMissingSource++;

                            continue;
                        }

                        if ($ledger->posting_datetime->isSameDay($correctDate)) {
                            continue;
                        }

                        $ledger->update(['posting_datetime' => $correctDate]);
                        $totalCorrected++;
                    }
                });
        }

        if ($dryRun) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->line('');
        $this->info("Rows corrected: {$totalCorrected}");
        if ($totalSkippedMissingSource > 0) {
            $this->warn("Rows skipped (source document no longer exists): {$totalSkippedMissingSource}");
        }

        return self::SUCCESS;
    }
}
