<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Enums\DocumentStatus;
use App\Enums\StockVoucherType;
use App\Models\Delivery;
use App\Models\FifoLayer;
use App\Models\FifoLayerConsumption;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\PurchaseReturn;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Services\FifoLayerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time historical backfill for FIFO cost layers — every document submitted before this
 * feature shipped moved real stock (StockLedger already has the qty), but never created a
 * FifoLayer/FifoLayerConsumption, since the hooks didn't exist yet. This walks every
 * IN-then-OUT event chronologically and creates what FifoLayerService::receive()/consume()
 * would have created at the time, using each document's own stored rate/cost — never a
 * guessed one.
 *
 * Idempotent: every write is guarded by "does a layer/consumption already exist for this
 * exact source document" — running this command again after new documents have shipped
 * through the real hooks (or after a partial prior run) only processes what's still missing.
 *
 * Deliberately excluded from replay (both because Stock Ledger itself never recorded them
 * historically, so there's nothing to reconcile against):
 * - StockIn (the old, frontend-less "quick stock-in" — has no cost field at all).
 * - Credit Note restock (restock=true was "intent only" until this same ticket wired it —
 *   no historical restock ever moved the ledger).
 * - Reversed Purchase Returns are skipped as a pair (both the original OUT and its reversing
 *   IN) — their net ledger effect is already zero, so skipping both is safe.
 *
 * A line whose stored cost is null/zero (a Goods Receipt rate, or a Stock Adjustment's
 * unit_cost on a found-more-than-expected line) is never silently defaulted to zero — it's
 * skipped and reported separately for manual entry, per the ticket's explicit instruction.
 */
class BackfillFifoLayersCommand extends Command
{
    protected $signature = 'fifo:backfill {--dry-run : Compute and report without saving any changes}';

    protected $description = 'Backfill FIFO cost layers from historical Goods Receipt/Delivery/Transfer/Adjustment/Purchase Return documents, then print a reconciliation report.';

    private array $missingCosts = [];

    private int $layersCreated = 0;

    private int $consumptionsCreated = 0;

    public function handle(FifoLayerService $fifo): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        $this->backfillGoodsReceipts($fifo);
        $this->backfillDeliveries($fifo);
        $this->backfillStockTransfers($fifo);
        $this->backfillStockAdjustments($fifo);
        $this->backfillPurchaseReturns($fifo);

        $mismatches = $this->reconcile();

        if ($dryRun) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->line('');
        $this->info("Layers created: {$this->layersCreated}");
        $this->info("Consumption events recorded: {$this->consumptionsCreated}");

        if ($this->missingCosts !== []) {
            $this->line('');
            $this->warn('Skipped — missing cost, needs manual entry:');
            $this->table(['Document', 'Item', 'Qty', 'Field'], $this->missingCosts);
        }

        $this->line('');
        if ($mismatches === []) {
            $this->info('Reconciliation: every item/warehouse with ledger activity matches its FIFO layer total.');
        } else {
            $this->warn('Reconciliation mismatches (ledger balance vs. FIFO layer qty_remaining):');
            $this->table(['Item', 'Warehouse', 'Ledger Balance', 'FIFO Qty', 'Difference'], $mismatches);
        }

        return self::SUCCESS;
    }

    private function alreadyHasLayer(StockVoucherType $sourceType, string $sourceId, string $itemId): bool
    {
        return FifoLayer::query()
            ->where('source_type', $sourceType->value)
            ->where('source_id', $sourceId)
            ->where('item_id', $itemId)
            ->exists();
    }

    private function alreadyConsumed(StockVoucherType $sourceType, string $sourceId, string $itemId): bool
    {
        return FifoLayerConsumption::query()
            ->where('consuming_source_type', $sourceType->value)
            ->where('consuming_source_id', $sourceId)
            ->whereHas('fifoLayer', fn ($q) => $q->where('item_id', $itemId))
            ->exists();
    }

    private function backfillGoodsReceipts(FifoLayerService $fifo): void
    {
        $this->info('Goods Receipts...');

        GoodsReceipt::query()
            ->where('status', DocumentStatus::SUBMITTED)
            ->with('items')
            ->orderBy('receipt_date')
            ->each(function (GoodsReceipt $gr) use ($fifo) {
                foreach ($gr->items as $line) {
                    if ($this->alreadyHasLayer(StockVoucherType::GOODS_RECEIPT, $gr->id, $line->item_id)) {
                        continue;
                    }

                    if ((float) $line->rate <= 0) {
                        $this->missingCosts[] = [$gr->document_number, $line->item_code, (float) $line->qty, 'rate'];

                        continue;
                    }

                    $fifo->receive(
                        itemId: $line->item_id,
                        warehouseId: $gr->warehouse_id,
                        qty: (float) $line->qty,
                        unitCost: (float) $line->rate,
                        sourceType: StockVoucherType::GOODS_RECEIPT,
                        sourceId: $gr->id,
                        sourceDocumentNumber: $gr->document_number,
                        receivedDate: $gr->receipt_date,
                    );
                    $this->layersCreated++;
                }
            });
    }

    private function backfillDeliveries(FifoLayerService $fifo): void
    {
        $this->info('Deliveries...');

        Delivery::query()
            ->where('status', DeliveryStatus::COMPLETE)
            ->with('items')
            ->orderBy('delivery_date')
            ->each(function (Delivery $delivery) use ($fifo) {
                foreach ($delivery->items as $line) {
                    if ($this->alreadyConsumed(StockVoucherType::DELIVERY, $delivery->id, $line->item_id)) {
                        continue;
                    }

                    $fifo->consume($line->item_id, $delivery->warehouse_id, (float) $line->qty, StockVoucherType::DELIVERY, $delivery->id);
                    $this->consumptionsCreated++;
                }
            });
    }

    private function backfillStockTransfers(FifoLayerService $fifo): void
    {
        $this->info('Stock Transfers...');

        StockTransfer::query()
            ->where('status', DocumentStatus::SUBMITTED)
            ->with('items')
            ->orderBy('transfer_date')
            ->each(function (StockTransfer $transfer) use ($fifo) {
                foreach ($transfer->items as $line) {
                    if ($this->alreadyConsumed(StockVoucherType::STOCK_TRANSFER, $transfer->id, $line->item_id)) {
                        continue;
                    }

                    $consumption = $fifo->consume($line->item_id, $transfer->source_warehouse_id, (float) $line->qty, StockVoucherType::STOCK_TRANSFER, $transfer->id);
                    $this->consumptionsCreated++;

                    $fifo->receive(
                        itemId: $line->item_id,
                        warehouseId: $transfer->destination_warehouse_id,
                        qty: (float) $line->qty,
                        unitCost: $consumption->weightedAverageUnitCost,
                        sourceType: StockVoucherType::STOCK_TRANSFER,
                        sourceId: $transfer->id,
                        sourceDocumentNumber: $transfer->document_number,
                        receivedDate: $transfer->transfer_date,
                    );
                    $this->layersCreated++;
                }
            });
    }

    private function backfillStockAdjustments(FifoLayerService $fifo): void
    {
        $this->info('Stock Adjustments...');

        StockAdjustment::query()
            ->where('status', DocumentStatus::SUBMITTED)
            ->with('items')
            ->orderBy('adjustment_date')
            ->each(function (StockAdjustment $adjustment) use ($fifo) {
                foreach ($adjustment->items as $line) {
                    $diff = (float) $line->difference_qty;

                    if (abs($diff) < 0.00005) {
                        continue;
                    }

                    if ($diff > 0) {
                        if ($this->alreadyHasLayer(StockVoucherType::STOCK_ADJUSTMENT, $adjustment->id, $line->item_id)) {
                            continue;
                        }

                        if ($line->unit_cost === null || (float) $line->unit_cost <= 0) {
                            $this->missingCosts[] = [$adjustment->document_number, $line->item_code, $diff, 'unit_cost'];

                            continue;
                        }

                        $fifo->receive(
                            itemId: $line->item_id,
                            warehouseId: $adjustment->warehouse_id,
                            qty: $diff,
                            unitCost: (float) $line->unit_cost,
                            sourceType: StockVoucherType::STOCK_ADJUSTMENT,
                            sourceId: $adjustment->id,
                            sourceDocumentNumber: $adjustment->document_number,
                            receivedDate: $adjustment->adjustment_date,
                        );
                        $this->layersCreated++;
                    } else {
                        if ($this->alreadyConsumed(StockVoucherType::STOCK_ADJUSTMENT, $adjustment->id, $line->item_id)) {
                            continue;
                        }

                        $fifo->consume($line->item_id, $adjustment->warehouse_id, abs($diff), StockVoucherType::STOCK_ADJUSTMENT, $adjustment->id);
                        $this->consumptionsCreated++;
                    }
                }
            });
    }

    private function backfillPurchaseReturns(FifoLayerService $fifo): void
    {
        $this->info('Purchase Returns...');

        PurchaseReturn::query()
            ->where('status', DocumentStatus::SUBMITTED)
            ->where('is_reversed', false)
            ->with('items')
            ->orderBy('return_date')
            ->each(function (PurchaseReturn $return) use ($fifo) {
                foreach ($return->items as $line) {
                    if ((float) $line->qty_returned <= 0) {
                        continue;
                    }

                    if ($this->alreadyConsumed(StockVoucherType::PURCHASE_RETURN, $return->id, $line->item_id)) {
                        continue;
                    }

                    $fifo->consume($line->item_id, $line->warehouse_id, (float) $line->qty_returned, StockVoucherType::PURCHASE_RETURN, $return->id);
                    $this->consumptionsCreated++;
                }
            });
    }

    /** @return array<int, array{0: string, 1: string, 2: float, 3: float, 4: float}> */
    private function reconcile(): array
    {
        $mismatches = [];

        $pairs = StockLedger::query()->select('item_id', 'warehouse_id')->distinct()->get();

        foreach ($pairs as $pair) {
            $ledgerBalance = (float) StockLedger::query()
                ->where('item_id', $pair->item_id)
                ->where('warehouse_id', $pair->warehouse_id)
                ->orderByDesc('posting_datetime')
                ->orderByDesc('id')
                ->value('balance_qty');

            $fifoQty = (float) FifoLayer::query()
                ->where('item_id', $pair->item_id)
                ->where('warehouse_id', $pair->warehouse_id)
                ->sum('qty_remaining');

            $diff = round($ledgerBalance - $fifoQty, 4);

            if (abs($diff) < 0.0005) {
                continue;
            }

            $item = Item::query()->find($pair->item_id);
            $mismatches[] = [$item?->item_code ?? $pair->item_id, $pair->warehouse_id, $ledgerBalance, $fifoQty, $diff];
        }

        return $mismatches;
    }
}
