<?php

namespace App\Services\Import;

/**
 * Parses the legacy "Product Purchase Report" export — a per-item, per-category AGGREGATE for one
 * date range, never a per-document transaction list (confirmed against the real file: no Document
 * No/Date/Supplier/UOM/Unit Price/Discount column exists anywhere in it). See
 * PurchaseHistoryImportService for why this can never create a Goods Receipt or Purchase Order —
 * there's no document identity to attach one to, only a period-level total per item.
 *
 * Rows 1-4 are report preamble (row 3 carries "Date From"/"Date To" under row 2's matching
 * headers), row 5 is the real column header (ITEM #, DESCRIPTION, INV, DN, TOTAL, CN, NET AMT,
 * QTY. PUR., CN. QTY., NET QTY.), data starts row 6. Row shape is structural, not by fixed
 * position:
 * - Category row (e.g. "SEMEN"): ITEM # has text, DESCRIPTION blank — purely a section label,
 *   nothing to capture.
 * - Item row: ITEM # has the item code, DESCRIPTION has the full item name and is never blank.
 * - Sub-total row: ITEM # blank, DESCRIPTION starts with "Sub-Total [" — the category's own
 *   running total, would double-count every item in it if not skipped.
 * - The file's own trailing "Printed By :" row is the grand total, likewise skipped.
 */
final class ProductPurchaseReportParser
{
    private const AMOUNT_EPSILON = 0.01;

    public const TITLE = 'PRODUCT PURCHASE REPORT';

    private const DATA_START_ROW = 6;

    /**
     * @return array{items: array<int, array{item_code: string, item_name: string, qty: float, amount: float, avg_price: float}>, period_from: ?string, period_to: ?string, warnings: array<int, string>}
     */
    public function parse(array $rawRows): array
    {
        $periodFrom = DataCleaner::normalizeDate($this->toStringOrNull($rawRows[2][0] ?? null));
        $periodTo = DataCleaner::normalizeDate($this->toStringOrNull($rawRows[2][1] ?? null));

        $dataRows = array_slice($rawRows, self::DATA_START_ROW - 1);

        $decimalStyle = DataCleaner::detectDecimalStyle(array_merge(
            array_column($dataRows, 6), array_column($dataRows, 9),
        ));

        $flatItems = [];
        $warnings = [];

        foreach ($dataRows as $row) {
            $itemCode = DataCleaner::normalizeText($this->toStringOrNull($row[0] ?? null));
            $description = DataCleaner::normalizeText($this->toStringOrNull($row[1] ?? null));

            if ($itemCode === null && $description === null && $this->allBlank($row, [2, 3, 4, 5, 6, 7, 8, 9])) {
                continue; // blank separator row
            }

            if ($itemCode !== null && stripos($itemCode, 'Printed By') === 0) {
                continue; // the file's own trailing grand total
            }

            if ($description !== null && stripos($description, 'Sub-Total') === 0) {
                continue; // "Sub-Total [KATEGORI]" row — already counted per item above it
            }

            if ($description === null) {
                continue; // category row (e.g. "SEMEN") — a pure section label, nothing to capture
            }

            if ($itemCode === null) {
                $warnings[] = "Baris item \"{$description}\" tidak punya kode item (ITEM #) — dilewati.";

                continue;
            }

            $netAmt = DataCleaner::normalizeNumber($row[6] ?? null, $decimalStyle) ?? 0.0;
            $netQty = DataCleaner::normalizeNumber($row[9] ?? null, $decimalStyle) ?? 0.0;

            $flatItems[] = ['item_code' => $itemCode, 'item_name' => $description, 'qty' => $netQty, 'amount' => $netAmt];
        }

        $grouped = [];
        foreach ($flatItems as $item) {
            $key = $item['item_code'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = $item;
            } else {
                $grouped[$key]['qty'] += $item['qty'];
                $grouped[$key]['amount'] += $item['amount'];
            }
        }

        $items = array_map(function ($item) {
            // A real 0-qty/0-amount line (e.g. a bonus/free-goods item) is valid data, not a
            // division error — same posture the old parser already took for this exact case.
            $item['avg_price'] = $item['qty'] > self::AMOUNT_EPSILON ? round($item['amount'] / $item['qty'], 2) : 0.0;

            return $item;
        }, array_values($grouped));

        return ['items' => $items, 'period_from' => $periodFrom, 'period_to' => $periodTo, 'warnings' => $warnings];
    }

    private function allBlank(array $row, array $indexes): bool
    {
        foreach ($indexes as $i) {
            if (DataCleaner::blankToNull($row[$i] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : (string) $value;
    }
}
