<?php

namespace App\Services;

use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\FifoLayer;
use App\Repositories\FifoLayerConsumptionRepository;
use App\Repositories\FifoLayerRepository;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * FIFO cost layer engine — sits alongside StockLedgerService (which owns
 * qty), never inside it: Stock Transfer's destination leg needs the
 * *result* of consuming the source leg (its weighted-average cost) before
 * it can create the destination layer, a data dependency StockLedgerService
 * ::record() can't express on its own. So each stock-moving service calls
 * both StockLedgerService (qty) and this service (cost) explicitly, within
 * the same DB::transaction() it already has. Write-path map:
 *
 * - GoodsReceiptService::submit()        -> receive() per line, unit cost = the line's own rate.
 * - DeliveryService::complete()          -> consume() per line.
 * - StockTransferService::submit()       -> consume() at source, then receive() at destination
 *                                            using that result's weightedAverageUnitCost.
 * - StockAdjustmentService::submit()     -> recordToBalance()'s signed qty_change decides:
 *                                            positive -> receive() (unit cost from a new form
 *                                            input, no natural cost source exists); negative -> consume().
 * - PurchaseReturnService::submit()      -> consume() (goods leaving to the supplier).
 * - PurchaseReturnService::reverse()     -> reverseConsumption() (exact original layers restored).
 * - CreditNoteService::submit()          -> for restock=true lines: receive(), unit cost from
 *                                            averageConsumedCost() against the original Delivery.
 * - OpeningStockService::submit()/cancel() -> receive() / reverseReceipt().
 *
 * Every stock document except PurchaseReturn (and the new OpeningStock) has cancel() hard-forbidden
 * app-wide today, so reverseConsumption()/reverseReceipt() only ever need to serve those two.
 */
class FifoLayerService
{
    public function __construct(
        protected FifoLayerRepository $fifoLayerRepository,
        protected FifoLayerConsumptionRepository $fifoLayerConsumptionRepository,
    ) {}

    public function receive(
        string $itemId,
        string $warehouseId,
        float $qty,
        float $unitCost,
        StockVoucherType $sourceType,
        string $sourceId,
        ?string $sourceDocumentNumber,
        DateTimeInterface $receivedDate,
    ): FifoLayer {
        return DB::transaction(function () use ($itemId, $warehouseId, $qty, $unitCost, $sourceType, $sourceId, $sourceDocumentNumber, $receivedDate) {
            return $this->fifoLayerRepository->create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'source_type' => $sourceType->value,
                'source_id' => $sourceId,
                'source_document_number' => $sourceDocumentNumber,
                'received_date' => $receivedDate,
                'qty_in' => $qty,
                'qty_remaining' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => round($qty * $unitCost, 2),
            ]);
        });
    }

    /**
     * Consumes the oldest layers first, splitting across as many as needed. Rejects outright
     * (never a zero-cost layer) when the item/warehouse doesn't have enough remaining qty —
     * matches DeliveryService::assertSufficientStock()'s existing "block, don't silently
     * corrupt" philosophy, just enforced at the cost-layer level instead of the ledger level.
     */
    public function consume(
        string $itemId,
        string $warehouseId,
        float $qty,
        StockVoucherType $sourceType,
        string $sourceId,
    ): FifoConsumptionResult {
        return DB::transaction(function () use ($itemId, $warehouseId, $qty, $sourceType, $sourceId) {
            $layers = $this->fifoLayerRepository->candidatesForConsumption($itemId, $warehouseId);

            $available = (float) $layers->sum(fn (FifoLayer $l) => (float) $l->qty_remaining);

            if ($qty > $available + 0.00005) {
                throw new BusinessException(
                    "Insufficient FIFO layers to consume {$qty}: only {$available} remaining for this item/warehouse.",
                );
            }

            $remaining = $qty;
            $totalCost = 0.0;
            $lines = [];

            foreach ($layers as $layer) {
                if ($remaining <= 0.00005) {
                    break;
                }

                $take = min($remaining, (float) $layer->qty_remaining);

                if ($take <= 0) {
                    continue;
                }

                $lineCost = round($take * (float) $layer->unit_cost, 2);
                $totalCost += $lineCost;

                $layer->qty_remaining = (float) $layer->qty_remaining - $take;
                $layer->save();

                $this->fifoLayerConsumptionRepository->create([
                    'fifo_layer_id' => $layer->id,
                    'consuming_source_type' => $sourceType->value,
                    'consuming_source_id' => $sourceId,
                    'qty_consumed' => $take,
                    'unit_cost' => $layer->unit_cost,
                    'total_cost' => $lineCost,
                ]);

                $lines[] = [
                    'fifo_layer_id' => $layer->id,
                    'qty_consumed' => $take,
                    'unit_cost' => (float) $layer->unit_cost,
                    'total_cost' => $lineCost,
                ];

                $remaining -= $take;
            }

            $weightedAverage = $qty > 0 ? round($totalCost / $qty, 2) : 0.0;

            return new FifoConsumptionResult($totalCost, $weightedAverage, $lines);
        });
    }

    /**
     * Undoes layer(s) a voucher created — only when none of them has been touched by a
     * consumption yet. Used by OpeningStockService::cancel(); the ticket's own rule ("Cancel
     * harus ... ditolak bila layer-nya sudah terkonsumsi sebagian").
     */
    public function reverseReceipt(StockVoucherType $sourceType, string $sourceId): void
    {
        DB::transaction(function () use ($sourceType, $sourceId) {
            $layers = $this->fifoLayerRepository->bySource($sourceType->value, $sourceId);

            foreach ($layers as $layer) {
                if (abs((float) $layer->qty_remaining - (float) $layer->qty_in) > 0.00005) {
                    throw new BusinessException(
                        "Cannot reverse: a layer created by {$sourceType->value} {$sourceId} has already been partially or fully consumed.",
                    );
                }
            }

            foreach ($layers as $layer) {
                $layer->delete();
            }
        });
    }

    /**
     * Undoes layer(s) a voucher consumed, restoring qty_remaining on the exact original
     * layers via the consumption audit trail — never a new averaged-cost layer. Idempotent:
     * already-reversed consumption rows are excluded by the repository query, so calling this
     * twice for the same voucher is a safe no-op the second time.
     */
    public function reverseConsumption(StockVoucherType $sourceType, string $sourceId): void
    {
        DB::transaction(function () use ($sourceType, $sourceId) {
            $consumptions = $this->fifoLayerConsumptionRepository->unreversedForSource($sourceType->value, $sourceId);

            foreach ($consumptions as $consumption) {
                $layer = $consumption->fifoLayer()->lockForUpdate()->firstOrFail();
                $layer->qty_remaining = (float) $layer->qty_remaining + (float) $consumption->qty_consumed;
                $layer->save();

                $consumption->update(['reversed' => true, 'reversed_at' => now()]);
            }
        });
    }

    /**
     * Weighted-average unit cost of what a voucher's consumption took for one item — read-only,
     * no mutation. Used where a new layer's cost must be *derived* from a past consumption
     * rather than restored exactly: Stock Transfer's destination leg (mirrors the source leg's
     * consumption within the same submit()) and Credit Note restock (mirrors the original
     * Delivery's consumption for that item — a documented simplification, not an exact
     * per-layer restore, since a Credit Note can partially credit a Delivery and consumption
     * rows aren't split at that granularity).
     */
    /**
     * Read-only preview of what consume() would do, for a line still in Draft — walks the same
     * oldest-first layers without locking or writing anything. Used by Issue Stock's line item
     * table to show the FIFO-computed Unit Cost before Submit (the ticket's "read-only, system-
     * calculated" column). available_qty lets the form flag an over-limit qty inline, ahead of
     * the real rejection consume() throws at submit time.
     *
     * @return array{unit_cost: float, available_qty: float}
     */
    public function previewConsumption(string $itemId, string $warehouseId, float $qty): array
    {
        $layers = $this->fifoLayerRepository->candidatesForPreview($itemId, $warehouseId);
        $available = (float) $layers->sum(fn (FifoLayer $l) => (float) $l->qty_remaining);

        $remaining = $qty;
        $totalCost = 0.0;

        foreach ($layers as $layer) {
            if ($remaining <= 0.00005) {
                break;
            }

            $take = min($remaining, (float) $layer->qty_remaining);
            $totalCost += $take * (float) $layer->unit_cost;
            $remaining -= $take;
        }

        $consumed = $qty - max($remaining, 0);
        $unitCost = $consumed > 0 ? round($totalCost / $consumed, 2) : 0.0;

        return ['unit_cost' => $unitCost, 'available_qty' => $available];
    }

    public function averageConsumedCost(string $itemId, StockVoucherType $sourceType, string $sourceId): float
    {
        $consumptions = $this->fifoLayerConsumptionRepository->forSourceAndItem($sourceType->value, $sourceId, $itemId);

        $totalQty = (float) $consumptions->sum(fn ($c) => (float) $c->qty_consumed);

        if ($totalQty <= 0) {
            return 0.0;
        }

        $totalCost = (float) $consumptions->sum(fn ($c) => (float) $c->total_cost);

        return round($totalCost / $totalQty, 2);
    }
}
