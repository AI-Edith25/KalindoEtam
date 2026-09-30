<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Services\StockAdjustmentService;
use App\Services\StockLedgerService;
use Throwable;

/**
 * "Smart" Stock Adjustment import — reads the exact same raw legacy stock-snapshot exports as
 * SmartOpeningStockImportService (Stock Balance, FIFO Opening Quantity, ...) via the shared
 * RawStockFileParser, but for the case Opening Stock structurally can't handle: correcting
 * CURRENT stock to match a newer snapshot for items that already have prior FIFO activity.
 * Opening Stock can only ever be an item's very first stock event in a warehouse (see
 * SmartOpeningStockImportService::excludeAlreadyOpenedItems()) — a "re-import a newer Stock
 * Balance" need for already-opened items is a stock count RECONCILIATION, which is what
 * StockAdjustment exists for.
 *
 * A row's Qty/Balance column is read as a COUNTED balance ("this is what the file says stock
 * actually is"), never a delta to add — StockAdjustmentService::submit() reads the item's live
 * ledger balance and only moves it by the difference (StockLedgerService::recordToBalance()).
 * Re-running the same file twice is therefore safe and idempotent: the second run's counted_qty
 * equals what the first run already reconciled to, so every difference comes out 0 and nothing
 * posts — unlike Opening Stock, there is no "already imported" state to protect against here.
 *
 * Only lines whose counted qty actually differs from the live system balance become part of a
 * Stock Adjustment document; 0-difference lines are silently dropped rather than forcing a no-op
 * line through StockAdjustment's required `reason` field. A qty INCREASE with no usable unit cost
 * is excluded and reported separately (see excludeCostlessIncreases()) instead of letting
 * StockAdjustmentService reject the whole document — same "don't fail 70 clean lines over 1 bad
 * one" posture as SmartOpeningStockImportService.
 */
class SmartStockAdjustmentImportService
{
    private const DIFFERENCE_EPSILON = 0.0001;

    public function __construct(
        protected RawStockFileParser $rawParser,
        protected StockLedgerService $stockLedgerService,
        protected StockAdjustmentService $stockAdjustmentService,
    ) {}

    /**
     * @return array{error: string}|array{
     *   total_rows: int,
     *   skipped_rows: array<int, array{row: int, reason: string}>,
     *   warehouses_detected: array<string, int>,
     *   groups: array<int, array{warehouse_code: string, warehouse_id: string, adjustment_date: string, lines: array, changed_line_count: int}>,
     *   unmatched_items: array,
     *   unmatched_warehouses: array,
     *   price_conflicts: array,
     *   cost_missing_items: array<int, array{item_code: string, warehouse_code: string, system_qty: float, counted_qty: float}>,
     * }
     */
    public function preflight(string $absolutePath, string $extension, ?string $metadataDate = null): array
    {
        return $this->parse($absolutePath, $extension, $metadataDate);
    }

    /**
     * @return array{documents_created: int, warehouses: string[], lines_adjusted: int, unchanged_count: int, cost_missing_count: int, failures: array<int, array{warehouse_code: string, adjustment_date: string, reason: string}>}
     */
    public function commit(string $absolutePath, string $extension, ?string $metadataDate = null): array
    {
        $parsed = $this->parse($absolutePath, $extension, $metadataDate);

        if (isset($parsed['error'])) {
            return ['documents_created' => 0, 'warehouses' => [], 'lines_adjusted' => 0, 'unchanged_count' => 0, 'cost_missing_count' => 0, 'failures' => [['warehouse_code' => '-', 'adjustment_date' => '-', 'reason' => $parsed['error']]]];
        }

        $documentsCreated = 0;
        $warehouses = [];
        $linesAdjusted = 0;
        $unchangedCount = 0;
        $failures = [];

        foreach ($parsed['groups'] as $group) {
            $changedLines = array_values(array_filter($group['lines'], fn ($l) => abs($l['difference_qty']) > self::DIFFERENCE_EPSILON));
            $unchangedCount += count($group['lines']) - count($changedLines);

            if ($changedLines === []) {
                continue;
            }

            try {
                $this->stockAdjustmentService->create([
                    'warehouse_id' => $group['warehouse_id'],
                    'adjustment_date' => $group['adjustment_date'],
                    'remarks' => 'Diimpor dari Stock Balance (legacy) — rekonsiliasi stok ke saldo hasil hitung terbaru.',
                    'items' => array_map(fn ($line) => [
                        'item_id' => $line['item_id'],
                        'counted_qty' => $line['qty'],
                        'unit_cost' => $line['unit_cost'],
                        'reason' => 'Rekonsiliasi dari import Stock Balance (legacy).',
                    ], $changedLines),
                ]);

                $documentsCreated++;
                $warehouses[] = $group['warehouse_code'];
                $linesAdjusted += count($changedLines);
            } catch (BusinessException|Throwable $e) {
                $failures[] = ['warehouse_code' => $group['warehouse_code'], 'adjustment_date' => $group['adjustment_date'], 'reason' => $e->getMessage()];
            }
        }

        $costMissingCount = count($parsed['cost_missing_items']);

        return ['documents_created' => $documentsCreated, 'warehouses' => array_values(array_unique($warehouses)), 'lines_adjusted' => $linesAdjusted, 'unchanged_count' => $unchangedCount, 'cost_missing_count' => $costMissingCount, 'failures' => $failures];
    }

    private function parse(string $absolutePath, string $extension, ?string $metadataDate): array
    {
        $parsed = $this->rawParser->parseRows($absolutePath, $extension, $metadataDate);

        if (isset($parsed['error'])) {
            return $parsed;
        }

        $resolved = $this->rawParser->resolveItemsAndWarehouses($parsed['clean_rows']);

        [$summedRows, $priceConflicts] = $this->rawParser->sumDuplicates($resolved['resolved_rows']);

        $rawGroups = $this->rawParser->groupByWarehouseAndDate($summedRows, $resolved['items'], $resolved['warehouses']);

        $costMissingItems = [];
        $groups = array_map(function ($g) use (&$costMissingItems) {
            return $this->withDifferences($g, $costMissingItems);
        }, $rawGroups);

        return [
            'total_rows' => $parsed['total_rows'],
            'skipped_rows' => $parsed['skipped_rows'],
            'warehouses_detected' => $this->rawParser->warehousesDetected($resolved['resolved_rows']),
            'groups' => $groups,
            'unmatched_items' => $resolved['unmatched_items'],
            'unmatched_warehouses' => $resolved['unmatched_warehouses'],
            'price_conflicts' => $priceConflicts,
            'cost_missing_items' => array_values($costMissingItems),
        ];
    }

    /**
     * Attaches each line's live system_qty/difference_qty — one batched peekBalances() query per
     * warehouse group, not per item — so the preview shows exactly what would change before
     * anything commits. A line whose counted qty exceeds the system balance but carries no usable
     * unit cost (StockAdjustmentService requires one to open a new FIFO layer for the found qty)
     * is dropped from the group and collected into $costMissingItems by reference, instead of
     * being left in to fail the whole document at commit time.
     */
    private function withDifferences(array $group, array &$costMissingItems): array
    {
        $itemIds = array_column($group['lines'], 'item_id');
        $systemBalances = $this->stockLedgerService->peekBalances($itemIds, $group['warehouse_id']);

        $lines = [];
        foreach ($group['lines'] as $line) {
            $systemQty = $systemBalances[$line['item_id']] ?? 0.0;
            $difference = round($line['qty'] - $systemQty, 4);

            if ($difference > self::DIFFERENCE_EPSILON && (float) $line['unit_cost'] <= 0.0) {
                $key = $line['item_code'].'|'.$group['warehouse_code'];
                $costMissingItems[$key] = [
                    'item_code' => $line['item_code'],
                    'warehouse_code' => $group['warehouse_code'],
                    'system_qty' => $systemQty,
                    'counted_qty' => $line['qty'],
                ];

                continue;
            }

            $lines[] = [...$line, 'system_qty' => $systemQty, 'difference_qty' => $difference];
        }

        return [
            'warehouse_code' => $group['warehouse_code'],
            'warehouse_id' => $group['warehouse_id'],
            'adjustment_date' => $group['date'],
            'lines' => $lines,
            'changed_line_count' => count(array_filter($lines, fn ($l) => abs($l['difference_qty']) > self::DIFFERENCE_EPSILON)),
        ];
    }
}
