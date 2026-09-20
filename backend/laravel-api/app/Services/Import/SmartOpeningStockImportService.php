<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\OpeningStockService;
use Illuminate\Support\Str;
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
    /** Column aliases for RAW legacy exports — distinct from OpeningStockImportTemplate's
     * synonyms, which are tuned for the already-clean template, not raw source files. */
    private const ALIASES = [
        'item_code' => ['item code', 'item #', 'item number', 'kode barang', 'code'],
        'description' => ['description', 'nama barang', 'item name'],
        'warehouse_code' => ['location', 'location code', 'warehouse', 'gudang'],
        'item_batch' => ['item batch', 'batch'],
        'qty' => ['qty', 'balance', 'saldo', 'quantity'],
        'unit_cost' => ['price', 'unit price', 'harga', 'unit cost'],
        'date' => ['date', 'tanggal', 'cutoff date'],
    ];

    public function __construct(protected FkResolver $fkResolver, protected OpeningStockService $openingStockService) {}

    /**
     * @return array{error: string}|array{
     *   total_rows: int, valid_row_count: int,
     *   skipped_rows: array<int, array{row: int, reason: string}>,
     *   warehouses_detected: array<string, int>,
     *   groups: array<int, array{warehouse_code: string, warehouse_id: string, cutoff_date: string, lines: array, total_qty: float, total_value: float}>,
     *   unmatched_items: array<int, array{item_code: string, rows: int[], suggestions: array}>,
     *   unmatched_warehouses: array<int, array{warehouse_code: string, rows: int[]}>,
     *   price_conflicts: array<int, array{item_code: string, warehouse_code: string, rows: int[], prices: float[]}>,
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
     * @return array{documents_created: int, warehouses: string[], total_qty: float, failures: array<int, array{warehouse_code: string, cutoff_date: string, reason: string}>}
     */
    public function commit(string $absolutePath, string $extension, ?string $metadataCutoffDate = null): array
    {
        $parsed = $this->parse($absolutePath, $extension, $metadataCutoffDate);

        if (isset($parsed['error'])) {
            return ['documents_created' => 0, 'warehouses' => [], 'total_qty' => 0.0, 'failures' => [['warehouse_code' => '-', 'cutoff_date' => '-', 'reason' => $parsed['error']]]];
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

        return ['documents_created' => $documentsCreated, 'warehouses' => array_values(array_unique($warehouses)), 'total_qty' => $totalQty, 'failures' => $failures];
    }

    /** Shared parse+dedup+group logic — preflight() reports it, commit() acts on it, so they can never disagree. */
    private function parse(string $absolutePath, string $extension, ?string $metadataCutoffDate): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if ($rawRows === []) {
            return ['error' => 'File kosong atau tidak bisa dibaca.'];
        }

        $fields = $this->detectionFields();
        $detected = HeaderDetector::detect($rawRows, $fields);
        $headerRow = $rawRows[$detected['header_row'] - 1] ?? [];
        $columns = $this->mapColumns($headerRow, $fields);

        if (! isset($columns['item_code']) || ! isset($columns['qty'])) {
            return ['error' => 'Tidak bisa menemukan kolom Item Code dan/atau Qty/Balance di file ini — periksa kembali formatnya.'];
        }

        $dataRows = array_slice($rawRows, $detected['data_start_row'] - 1);

        $skippedRows = [];
        $cleanRows = [];

        foreach ($dataRows as $i => $row) {
            $rowNo = $detected['data_start_row'] + $i;

            if ($this->isBlankOrFooterRow($row, $columns)) {
                continue;
            }

            $itemCode = DataCleaner::blankToNull($columns['item_code'] !== null ? ($row[$columns['item_code']] ?? null) : null);
            if ($itemCode === null) {
                $skippedRows[] = ['row' => $rowNo, 'reason' => 'Item Code kosong.'];

                continue;
            }
            $itemCode = trim((string) $itemCode);

            $qty = DataCleaner::normalizeNumber($row[$columns['qty']] ?? null, 'dot_decimal');
            if ($qty === null) {
                $skippedRows[] = ['row' => $rowNo, 'reason' => 'Qty/Balance bukan angka.'];

                continue;
            }
            if (abs($qty) < 0.0001) {
                $skippedRows[] = ['row' => $rowNo, 'reason' => 'Balance 0 — tidak ada saldo untuk diimpor.'];

                continue;
            }

            $warehouseCode = isset($columns['warehouse_code']) ? DataCleaner::blankToNull($row[$columns['warehouse_code']] ?? null) : null;
            if ($warehouseCode === null) {
                $skippedRows[] = ['row' => $rowNo, 'reason' => 'Warehouse/Location kosong.'];

                continue;
            }
            $warehouseCode = trim((string) $warehouseCode);

            $rawDate = isset($columns['date']) ? DataCleaner::blankToNull($row[$columns['date']] ?? null) : null;
            $date = $rawDate !== null ? DataCleaner::normalizeDate((string) $rawDate) : null;
            $date ??= $metadataCutoffDate;
            if ($date === null) {
                $skippedRows[] = ['row' => $rowNo, 'reason' => 'Tanggal kosong dan tidak ada tanggal default dari metadata file.'];

                continue;
            }

            $unitCost = isset($columns['unit_cost']) ? (DataCleaner::normalizeNumber($row[$columns['unit_cost']] ?? null, 'dot_decimal') ?? 0.0) : 0.0;
            $itemBatch = isset($columns['item_batch']) ? DataCleaner::blankToNull($row[$columns['item_batch']] ?? null) : null;

            $cleanRows[] = [
                'row_no' => $rowNo,
                'item_code' => $itemCode,
                'warehouse_code' => $warehouseCode,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'item_batch' => $itemBatch !== null ? trim((string) $itemBatch) : null,
                'cutoff_date' => $date,
            ];
        }

        $itemClassification = $this->fkResolver->classify(Item::class, 'item_code', array_column($cleanRows, 'item_code'));
        $warehouseClassification = $this->fkResolver->classify(Warehouse::class, 'code', array_column($cleanRows, 'warehouse_code'));

        $unmatchedItems = $this->buildUnmatchedList($cleanRows, 'item_code', $itemClassification);
        $unmatchedWarehouses = $this->buildUnmatchedList($cleanRows, 'warehouse_code', $warehouseClassification);

        $unmatchedItemCodes = array_column($unmatchedItems, 'item_code');
        $unmatchedWarehouseCodes = array_column($unmatchedWarehouses, 'warehouse_code');

        $resolvedRows = array_filter(
            $cleanRows,
            fn ($r) => ! in_array($r['item_code'], $unmatchedItemCodes, true) && ! in_array($r['warehouse_code'], $unmatchedWarehouseCodes, true)
        );

        $items = Item::query()->whereIn('item_code', array_column($resolvedRows, 'item_code'))->get()->keyBy('item_code');
        $warehouses = Warehouse::query()->whereIn('code', array_column($resolvedRows, 'warehouse_code'))->get()->keyBy('code');

        [$summedRows, $priceConflicts] = $this->sumDuplicates($resolvedRows);

        $groups = $this->groupByWarehouseAndDate($summedRows, $items, $warehouses);

        $warehousesDetected = [];
        foreach ($resolvedRows as $r) {
            $warehousesDetected[$r['warehouse_code']] = ($warehousesDetected[$r['warehouse_code']] ?? 0) + 1;
        }

        return [
            // Excludes the footer/blank rows silently `continue`d above — those aren't
            // rows from the user's perspective, just structural noise in the file.
            'total_rows' => count($skippedRows) + count($cleanRows),
            'skipped_rows' => $skippedRows,
            'warehouses_detected' => $warehousesDetected,
            'groups' => $groups,
            'unmatched_items' => $unmatchedItems,
            'unmatched_warehouses' => $unmatchedWarehouses,
            'price_conflicts' => $priceConflicts,
        ];
    }

    /** @return \App\Services\Import\ImportFieldDefinition[] */
    private function detectionFields(): array
    {
        return array_map(
            fn ($name, $synonyms) => new ImportFieldDefinition($name, Str::headline($name), 'string', synonyms: $synonyms),
            array_keys(self::ALIASES),
            array_values(self::ALIASES),
        );
    }

    /** @return array<string, int> field name => raw column index */
    private function mapColumns(array $headerRow, array $fields): array
    {
        $vocabulary = [];
        foreach ($fields as $field) {
            foreach ([$field->name, $field->label, ...$field->synonyms] as $term) {
                $vocabulary[$this->normalize($term)] = $field->name;
            }
        }

        $map = [];
        foreach (array_values($headerRow) as $index => $cell) {
            $value = DataCleaner::blankToNull($cell);
            if ($value === null) {
                continue;
            }
            $key = $vocabulary[$this->normalize((string) $value)] ?? null;
            if ($key !== null && ! isset($map[$key])) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /** Same normalization rule as HeaderDetector/ImportBatchService — kept independent, see their own docblocks on why. */
    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }

    /** A row is structural noise (not data) if its Item Code is blank AND some cell in the row is a "Total..." label — the label's column varies per legacy export (Description in one, Item Group in another), so every cell is checked, not one fixed column. */
    private function isBlankOrFooterRow(array $row, array $columns): bool
    {
        $itemCode = $columns['item_code'] !== null ? DataCleaner::blankToNull($row[$columns['item_code']] ?? null) : null;

        $nonBlank = array_filter($row, fn ($v) => DataCleaner::blankToNull($v) !== null);
        if ($nonBlank === []) {
            return true;
        }

        if ($itemCode !== null) {
            return false;
        }

        foreach ($nonBlank as $cell) {
            if (is_string($cell) && stripos(trim($cell), 'total') !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{item_code?: string, warehouse_code?: string, rows: int[], suggestions: array}> */
    private function buildUnmatchedList(array $cleanRows, string $field, array $classification): array
    {
        $result = [];

        foreach ($classification as $value => $candidate) {
            if ($candidate['status'] === 'match') {
                continue;
            }

            $rows = array_values(array_map(fn ($r) => $r['row_no'], array_filter($cleanRows, fn ($r) => $r[$field] === $value)));

            $result[] = [$field => $value, 'rows' => $rows, 'suggestions' => $candidate['suggestions']];
        }

        return $result;
    }

    /**
     * Sums qty for duplicate (item_code, warehouse_code) rows sharing a blank item_batch. A
     * batch-tracked item (item_batch filled on any row in the group) is never merged with
     * anything — each stays its own line. A group with mismatched unit_cost is NOT merged —
     * it's reported as a price conflict and excluded from commit rather than silently averaged
     * or arbitrarily picking one row's price.
     *
     * @return array{0: array, 1: array<int, array{item_code: string, warehouse_code: string, rows: int[], prices: float[]}>}
     */
    private function sumDuplicates(array $rows): array
    {
        $batched = array_values(array_filter($rows, fn ($r) => $r['item_batch'] !== null));
        $poolable = array_values(array_filter($rows, fn ($r) => $r['item_batch'] === null));

        $groups = [];
        foreach ($poolable as $r) {
            $key = $r['item_code'].'|'.$r['warehouse_code'].'|'.$r['cutoff_date'];
            $groups[$key][] = $r;
        }

        $summed = [];
        $priceConflicts = [];

        foreach ($groups as $groupRows) {
            $prices = array_values(array_unique(array_map(fn ($r) => round($r['unit_cost'], 2), $groupRows)));

            if (count($prices) > 1) {
                $priceConflicts[] = [
                    'item_code' => $groupRows[0]['item_code'],
                    'warehouse_code' => $groupRows[0]['warehouse_code'],
                    'rows' => array_map(fn ($r) => $r['row_no'], $groupRows),
                    'prices' => $prices,
                ];

                continue;
            }

            $summed[] = [
                ...$groupRows[0],
                'qty' => array_sum(array_column($groupRows, 'qty')),
            ];
        }

        $conflictKeys = array_map(fn ($c) => $c['item_code'].'|'.$c['warehouse_code'], $priceConflicts);
        $summed = array_filter($summed, fn ($r) => ! in_array($r['item_code'].'|'.$r['warehouse_code'], $conflictKeys, true));

        return [[...$summed, ...$batched], $priceConflicts];
    }

    /** One OpeningStock document per (warehouse, cutoff_date) — an item's own resolved UOM is attached for display only, never sent to OpeningStockService::create(), which derives it itself from the Item. */
    private function groupByWarehouseAndDate(array $rows, $items, $warehouses): array
    {
        $groups = [];

        foreach ($rows as $r) {
            $item = $items->get($r['item_code']);
            $warehouse = $warehouses->get($r['warehouse_code']);
            if ($item === null || $warehouse === null) {
                continue;
            }

            $key = $warehouse->id.'|'.$r['cutoff_date'];
            $groups[$key]['warehouse_code'] ??= $warehouse->code;
            $groups[$key]['warehouse_id'] ??= $warehouse->id;
            $groups[$key]['cutoff_date'] ??= $r['cutoff_date'];
            $groups[$key]['lines'][] = [
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'uom' => $item->uom?->name,
                'qty' => $r['qty'],
                'unit_cost' => $r['unit_cost'],
            ];
        }

        return array_values(array_map(function ($group) {
            $group['total_qty'] = array_sum(array_column($group['lines'], 'qty'));
            $group['total_value'] = array_sum(array_map(fn ($l) => $l['qty'] * $l['unit_cost'], $group['lines']));

            return $group;
        }, $groups));
    }
}
