<?php

namespace App\Services\Import;

use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/**
 * Shared raw-legacy-export parsing for BOTH "Smart Import" pipelines that read a per-item stock
 * snapshot file (Stock Balance, FIFO Opening Quantity, and similar) — Opening Stock and Stock
 * Adjustment. The two differ only in what a resolved (item, warehouse, qty, cost, date) row
 * becomes downstream (a brand-new opening balance vs a correction to today's counted balance);
 * everything upstream of that — header detection, column aliasing, footer/blank-row skipping,
 * item/warehouse fuzzy-matching, duplicate-row summing, warehouse+date grouping — is identical,
 * so it lives here once instead of drifting across two copies.
 *
 * Extracted out of SmartOpeningStockImportService (its original, still-tested owner) when
 * SmartStockAdjustmentImportService needed the exact same file-reading behavior; opening stock's
 * own domain rule (an item can't be opening-stocked twice — see
 * SmartOpeningStockImportService::excludeAlreadyOpenedItems()) is NOT here, since Stock
 * Adjustment has no such rule at all.
 */
final class RawStockFileParser
{
    private const ALIASES = [
        'item_code' => ['item code', 'item #', 'item number', 'kode barang', 'code'],
        'description' => ['description', 'nama barang', 'item name'],
        'warehouse_code' => ['location', 'location code', 'warehouse', 'gudang'],
        'item_batch' => ['item batch', 'batch'],
        'qty' => ['qty', 'balance', 'saldo', 'quantity'],
        'unit_cost' => ['price', 'unit price', 'harga', 'unit cost'],
        'date' => ['date', 'tanggal', 'cutoff date'],
    ];

    public function __construct(protected FkResolver $fkResolver) {}

    /**
     * Reads the file and returns clean (item_code, warehouse_code, qty, unit_cost, item_batch,
     * date, row_no) rows — no item/warehouse master-data resolution yet.
     *
     * @return array{error: string}|array{clean_rows: array, skipped_rows: array<int, array{row: int, reason: string}>, total_rows: int}
     */
    public function parseRows(string $absolutePath, string $extension, ?string $metadataDate): array
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
            $date ??= $metadataDate;
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
                'date' => $date,
            ];
        }

        return ['clean_rows' => $cleanRows, 'skipped_rows' => $skippedRows, 'total_rows' => count($skippedRows) + count($cleanRows)];
    }

    /**
     * Fuzzy-matches every row's item_code/warehouse_code against master data, splitting rows into
     * resolved (both matched) and reported-unmatched — never guesses a match, never silently drops
     * an unmatched row without naming it.
     *
     * @return array{resolved_rows: array, items: \Illuminate\Support\Collection, warehouses: \Illuminate\Support\Collection, unmatched_items: array, unmatched_warehouses: array}
     */
    public function resolveItemsAndWarehouses(array $cleanRows): array
    {
        $itemClassification = $this->fkResolver->classify(Item::class, 'item_code', array_column($cleanRows, 'item_code'));
        $warehouseClassification = $this->fkResolver->classify(Warehouse::class, 'code', array_column($cleanRows, 'warehouse_code'));

        $unmatchedItems = $this->buildUnmatchedList($cleanRows, 'item_code', $itemClassification);
        $unmatchedWarehouses = $this->buildUnmatchedList($cleanRows, 'warehouse_code', $warehouseClassification);

        $unmatchedItemCodes = array_column($unmatchedItems, 'item_code');
        $unmatchedWarehouseCodes = array_column($unmatchedWarehouses, 'warehouse_code');

        $resolvedRows = array_values(array_filter(
            $cleanRows,
            fn ($r) => ! in_array($r['item_code'], $unmatchedItemCodes, true) && ! in_array($r['warehouse_code'], $unmatchedWarehouseCodes, true)
        ));

        $items = Item::query()->whereIn('item_code', array_column($resolvedRows, 'item_code'))->get()->keyBy('item_code');
        $warehouses = Warehouse::query()->whereIn('code', array_column($resolvedRows, 'warehouse_code'))->get()->keyBy('code');

        return [
            'resolved_rows' => $resolvedRows,
            'items' => $items,
            'warehouses' => $warehouses,
            'unmatched_items' => $unmatchedItems,
            'unmatched_warehouses' => $unmatchedWarehouses,
        ];
    }

    /**
     * Sums qty for duplicate (item_code, warehouse_code, date) rows sharing a blank item_batch. A
     * batch-tracked item (item_batch filled on any row in the group) is never merged with
     * anything — each stays its own line. A group with mismatched unit_cost is NOT merged — it's
     * reported as a price conflict and excluded rather than silently averaged or arbitrarily
     * picking one row's price.
     *
     * @return array{0: array, 1: array<int, array{item_code: string, warehouse_code: string, rows: int[], prices: float[]}>}
     */
    public function sumDuplicates(array $rows): array
    {
        $batched = array_values(array_filter($rows, fn ($r) => $r['item_batch'] !== null));
        $poolable = array_values(array_filter($rows, fn ($r) => $r['item_batch'] === null));

        $groups = [];
        foreach ($poolable as $r) {
            $key = $r['item_code'].'|'.$r['warehouse_code'].'|'.$r['date'];
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

    /** One group per (warehouse, date) — an item's own resolved UOM is attached for display only. */
    public function groupByWarehouseAndDate(array $rows, $items, $warehouses): array
    {
        $groups = [];

        foreach ($rows as $r) {
            $item = $items->get($r['item_code']);
            $warehouse = $warehouses->get($r['warehouse_code']);
            if ($item === null || $warehouse === null) {
                continue;
            }

            $key = $warehouse->id.'|'.$r['date'];
            $groups[$key]['warehouse_code'] ??= $warehouse->code;
            $groups[$key]['warehouse_id'] ??= $warehouse->id;
            $groups[$key]['date'] ??= $r['date'];
            $groups[$key]['lines'][] = [
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'uom' => $item->uom?->name,
                'qty' => $this->cleanWholeNumberDrift($item, $r['qty']),
                'unit_cost' => $r['unit_cost'],
            ];
        }

        return array_values(array_map(function ($group) {
            $group['total_qty'] = array_sum(array_column($group['lines'], 'qty'));
            $group['total_value'] = array_sum(array_map(fn ($l) => $l['qty'] * $l['unit_cost'], $group['lines']));

            return $group;
        }, $groups));
    }

    public function warehousesDetected(array $rows): array
    {
        $detected = [];
        foreach ($rows as $r) {
            $detected[$r['warehouse_code']] = ($detected[$r['warehouse_code']] ?? 0) + 1;
        }

        return $detected;
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
     * Legacy ledger exports carry B/F + IN - OUT balances computed upstream with more internal
     * precision than the file displays — a whole-number-only item (qty_category=unit, e.g. a
     * ZAK/sack count) can come through as 106515.999 instead of a clean 106516, which
     * QtyCategoryValidator (correctly strict at 1e-6 for real user entry) would otherwise reject
     * outright. Only true float drift is forgiven here (within WHOLE_NUMBER_DRIFT_TOLERANCE of a
     * whole number) — a genuinely fractional qty like 50.7 sacks still surfaces as a normal
     * validation failure instead of being silently rounded away.
     */
    private const WHOLE_NUMBER_DRIFT_TOLERANCE = 0.01;

    private function cleanWholeNumberDrift(Item $item, float $qty): float
    {
        if ($item->qty_category->decimalPlaces() !== 0) {
            return $qty;
        }

        $rounded = round($qty);

        return abs($qty - $rounded) <= self::WHOLE_NUMBER_DRIFT_TOLERANCE ? $rounded : $qty;
    }
}
