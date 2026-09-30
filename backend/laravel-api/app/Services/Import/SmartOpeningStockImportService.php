<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Models\FifoLayer;
use App\Services\OpeningStockService;
use Throwable;

/**
 * "Smart" Opening Stock import for raw, un-cleaned legacy exports (FIFO Opening
 * Quantity, Stock Balance, and similar) — separate from the strict-template
 * flow (OpeningStockImportTemplate/ImportBatchService), which still requires
 * a pre-cleaned file with exact column headers. This service never touches
 * that flow; it's an independent pipeline that happens to call the same
 * OpeningStockService::create() at the end, so business rules (qty_category,
 * cutoff-precedes-activity, non-negative cost) stay identical either way.
 *
 * File reading/parsing (header detection, column aliasing, item/warehouse
 * fuzzy-matching, duplicate summing, warehouse+date grouping) lives in
 * RawStockFileParser, shared with SmartStockAdjustmentImportService — this
 * class only adds its own domain rule (excludeAlreadyOpenedItems()) and
 * turns a clean group into an OpeningStock document.
 *
 * Modeled on PurchaseHistoryImportService's proven shape: preflight() always
 * runs synchronously (these files are hundreds of rows at most) and returns a
 * preview that must be seen before anything commits; commit() re-parses from
 * disk rather than trusting cached state, so preflight and commit can never
 * silently disagree. Deliberately synchronous end-to-end, not queued —
 * unlike every other importer in this app — see commit()'s docblock.
 *
 * Why a standalone pipeline instead of extending the generic per-row
 * ImportBatchService engine: that engine's contract (CreatesRelatedRecords)
 * is one row -> one document, which cannot express "many rows -> one
 * document per warehouse". Bending that contract would risk every other
 * import template; a separate service risks nothing there.
 */
class SmartOpeningStockImportService
{
    public function __construct(protected RawStockFileParser $rawParser, protected OpeningStockService $openingStockService) {}

    /**
     * @return array{error: string}|array{
     *   total_rows: int, valid_row_count: int,
     *   skipped_rows: array<int, array{row: int, reason: string}>,
     *   warehouses_detected: array<string, int>,
     *   groups: array<int, array{warehouse_code: string, warehouse_id: string, cutoff_date: string, lines: array, total_qty: float, total_value: float}>,
     *   unmatched_items: array<int, array{item_code: string, rows: int[], suggestions: array}>,
     *   unmatched_warehouses: array<int, array{warehouse_code: string, rows: int[]}>,
     *   price_conflicts: array<int, array{item_code: string, warehouse_code: string, rows: int[], prices: float[]}>,
     *   already_opened_items: array<int, array{item_code: string, warehouse_code: string, rows: int[]}>,
     * }
     */
    public function preflight(string $absolutePath, string $extension, ?string $metadataCutoffDate = null): array
    {
        $parsed = $this->parse($absolutePath, $extension, $metadataCutoffDate);

        if (isset($parsed['error'])) {
            return $parsed;
        }

        return [
            'total_rows' => $parsed['total_rows'],
            'valid_row_count' => array_sum(array_map(fn ($g) => count($g['lines']), $parsed['groups'])),
            'skipped_rows' => $parsed['skipped_rows'],
            'warehouses_detected' => $parsed['warehouses_detected'],
            'groups' => $parsed['groups'],
            'unmatched_items' => $parsed['unmatched_items'],
            'unmatched_warehouses' => $parsed['unmatched_warehouses'],
            'price_conflicts' => $parsed['price_conflicts'],
            'already_opened_items' => $parsed['already_opened_items'],
        ];
    }

    /**
     * Re-parses from disk (same file, same metadata date the batch was created with) and
     * actually creates one OpeningStock document per clean group. Groups with an unresolved
     * item/warehouse or a price conflict were already excluded during preflight() and are
     * excluded here too — never partially committed, never silently guessed.
     *
     * Synchronous, not queued: unlike every other importer here, which dispatches a
     * ShouldQueue job. These files are hundreds of rows at most (same justification
     * PurchaseHistoryImportService gives for its own synchronous preflight), and a queue
     * dependency is a real, observed failure mode on this app's production worker — no reason
     * to expose this feature to it when the file size doesn't need queuing at all.
     *
     * @return array{documents_created: int, warehouses: string[], total_qty: float, already_opened_count: int, failures: array<int, array{warehouse_code: string, cutoff_date: string, reason: string}>}
     */
    public function commit(string $absolutePath, string $extension, ?string $metadataCutoffDate = null): array
    {
        $parsed = $this->parse($absolutePath, $extension, $metadataCutoffDate);

        if (isset($parsed['error'])) {
            return ['documents_created' => 0, 'warehouses' => [], 'total_qty' => 0.0, 'already_opened_count' => 0, 'failures' => [['warehouse_code' => '-', 'cutoff_date' => '-', 'reason' => $parsed['error']]]];
        }

        $documentsCreated = 0;
        $warehouses = [];
        $totalQty = 0.0;
        $failures = [];

        foreach ($parsed['groups'] as $group) {
            try {
                $this->openingStockService->create([
                    'warehouse_id' => $group['warehouse_id'],
                    'cutoff_date' => $group['cutoff_date'],
                    'items' => array_map(fn ($line) => [
                        'item_id' => $line['item_id'],
                        'qty' => $line['qty'],
                        'unit_cost' => $line['unit_cost'],
                    ], $group['lines']),
                ]);

                $documentsCreated++;
                $warehouses[] = $group['warehouse_code'];
                $totalQty += $group['total_qty'];
            } catch (BusinessException|Throwable $e) {
                $failures[] = ['warehouse_code' => $group['warehouse_code'], 'cutoff_date' => $group['cutoff_date'], 'reason' => $e->getMessage()];
            }
        }

        $alreadyOpenedCount = array_sum(array_map(fn ($g) => count($g['rows']), $parsed['already_opened_items']));

        return ['documents_created' => $documentsCreated, 'warehouses' => array_values(array_unique($warehouses)), 'total_qty' => $totalQty, 'already_opened_count' => $alreadyOpenedCount, 'failures' => $failures];
    }

    /** Shared parse+dedup+group logic — preflight() reports it, commit() acts on it, so they can never disagree. */
    private function parse(string $absolutePath, string $extension, ?string $metadataCutoffDate): array
    {
        $parsed = $this->rawParser->parseRows($absolutePath, $extension, $metadataCutoffDate);

        if (isset($parsed['error'])) {
            return $parsed;
        }

        $resolved = $this->rawParser->resolveItemsAndWarehouses($parsed['clean_rows']);

        [$openableRows, $alreadyOpenedItems] = $this->excludeAlreadyOpenedItems($resolved['resolved_rows'], $resolved['items'], $resolved['warehouses']);

        [$summedRows, $priceConflicts] = $this->rawParser->sumDuplicates($openableRows);

        $groups = array_map(
            fn ($g) => ['warehouse_code' => $g['warehouse_code'], 'warehouse_id' => $g['warehouse_id'], 'cutoff_date' => $g['date'], 'lines' => $g['lines'], 'total_qty' => $g['total_qty'], 'total_value' => $g['total_value']],
            $this->rawParser->groupByWarehouseAndDate($summedRows, $resolved['items'], $resolved['warehouses']),
        );

        return [
            'total_rows' => $parsed['total_rows'],
            'skipped_rows' => $parsed['skipped_rows'],
            'warehouses_detected' => $this->rawParser->warehousesDetected($resolved['resolved_rows']),
            'groups' => $groups,
            'unmatched_items' => $resolved['unmatched_items'],
            'unmatched_warehouses' => $resolved['unmatched_warehouses'],
            'price_conflicts' => $priceConflicts,
            'already_opened_items' => $alreadyOpenedItems,
        ];
    }

    /**
     * Excludes rows whose item+warehouse already has FIFO activity on or before this row's own
     * cutoff date — OpeningStockService::create() would reject the *entire* document for a
     * single such item (assertCutoffPrecedesExistingActivity(), protecting FIFO ordering), which
     * would otherwise fail a whole warehouse group of 70 clean rows over 1 already-opened item.
     * Re-running a Stock Balance file that overlaps a prior import (or covers items opened at an
     * earlier cutoff) now only picks up the genuinely new items instead of importing nothing.
     *
     * One query for every (item, warehouse) pair in the file, not one per row.
     *
     * @return array{0: array, 1: array<int, array{item_code: string, warehouse_code: string, rows: int[]}>}
     */
    private function excludeAlreadyOpenedItems(array $rows, $items, $warehouses): array
    {
        $itemIds = $items->pluck('id')->all();
        $warehouseIds = $warehouses->pluck('id')->all();

        $earliestActivity = ($itemIds === [] || $warehouseIds === []) ? collect() : FifoLayer::query()
            ->whereIn('item_id', $itemIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('item_id, warehouse_id, MIN(received_date) as earliest_date')
            ->groupBy('item_id', 'warehouse_id')
            ->get()
            ->keyBy(fn ($row) => "{$row->item_id}|{$row->warehouse_id}");

        $eligible = [];
        $alreadyOpened = [];

        foreach ($rows as $r) {
            $item = $items->get($r['item_code']);
            $warehouse = $warehouses->get($r['warehouse_code']);

            if ($item === null || $warehouse === null) {
                continue; // unresolved — already excluded from $resolvedRows upstream, never reached in practice
            }

            $earliest = $earliestActivity->get("{$item->id}|{$warehouse->id}")?->earliest_date;

            if ($earliest !== null && $earliest < $r['date']) {
                $key = $r['item_code'].'|'.$r['warehouse_code'];
                $alreadyOpened[$key]['item_code'] ??= $r['item_code'];
                $alreadyOpened[$key]['warehouse_code'] ??= $r['warehouse_code'];
                $alreadyOpened[$key]['rows'][] = $r['row_no'];

                continue;
            }

            $eligible[] = $r;
        }

        return [$eligible, array_values($alreadyOpened)];
    }
}
