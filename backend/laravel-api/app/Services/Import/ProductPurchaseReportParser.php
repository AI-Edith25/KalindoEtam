<?php

namespace App\Services\Import;

/**
 * Parses the legacy "Product Purchase Report" export (Reports > Purchase > Purchase Orders'
 * Import) into document-number groups, each destined to become one Direct Goods Receipt —
 * see PurchaseHistoryImportService for why (this file has no PO number at all, only an
 * invoice/DO-style DOCUMENT #).
 *
 * Rows 1-4 are report preamble, row 5 is the real column header (DATE, DOCUMENT #, SUPPLIER NAME,
 * INV, DN, TOTAL, CN, NET AMT, QTY. PUR., CN. QTY., NET QTY.), data starts row 6. Row detection is
 * rule-based (never by fixed position — confirmed necessary against the real file: a document
 * number's line items are scattered across the WHOLE file, not contiguous, since rows are grouped
 * by item/category first, transaction second):
 *
 * - Category row (e.g. "KAWAT"): column A has text, column B blank, no " - " in column A.
 * - Item row (e.g. "BENDRAT TOKKA @ 20 KG - KAWAT BENDRAT TOKKA @ 20 KG"): column A has text
 *   containing " - ", column B blank — the segment before the first " - " is the item code.
 * - Transaction row: column A parses as a real date AND column B (DOCUMENT #) is non-blank.
 * - Everything else (an item's own unlabeled subtotal row, a "Sub-Total [X]" row, the file's
 *   trailing "Printed By :" grand total) has no date in column A and no " - " in column A — never
 *   posted, never even reported, pure structural noise.
 */
final class ProductPurchaseReportParser
{
    private const AMOUNT_EPSILON = 0.01;

    public const TITLE = 'PRODUCT PURCHASE REPORT';

    private const DATA_START_ROW = 6;

    /**
     * @return array{groups: array<int, array{document_number: string, date: string, supplier_name: string, items: array<int, array{item_code: string, item_name: string, qty: float, rate: float}>}>, warnings: array<int, string>}
     */
    public function parse(array $rawRows): array
    {
        $dataRows = array_slice($rawRows, self::DATA_START_ROW - 1);

        $decimalStyle = DataCleaner::detectDecimalStyle(array_merge(
            array_column($dataRows, 5), array_column($dataRows, 6), array_column($dataRows, 7),
            array_column($dataRows, 8), array_column($dataRows, 9), array_column($dataRows, 10),
        ));

        $flatRows = [];
        $warnings = [];
        $currentItem = null;

        foreach ($dataRows as $rowIndex => $row) {
            $colA = DataCleaner::normalizeText($this->toStringOrNull($row[0] ?? null));
            $colB = DataCleaner::normalizeText($this->toStringOrNull($row[1] ?? null));
            $colC = DataCleaner::normalizeText($this->toStringOrNull($row[2] ?? null));

            if ($colA === null && $colB === null && $colC === null && $this->allBlank($row, [5, 6, 7, 8, 9, 10])) {
                continue; // blank separator row
            }

            if ($colA !== null && stripos($colA, 'Printed By') === 0) {
                continue; // the file's own trailing grand total
            }

            if ($colC !== null && stripos($colC, 'Sub-Total') === 0) {
                continue; // "Sub-Total [KATEGORI]" row
            }

            if ($colA === null && $colB === null && $colC === null) {
                continue; // an item's own unlabeled subtotal row (blank A-C, numbers in D-K)
            }

            if ($colB === null) {
                // Category or item row — column A has text, everything else on the row is blank.
                if (str_contains($colA ?? '', ' - ')) {
                    [$code, $name] = array_map('trim', explode(' - ', $colA, 2));
                    $currentItem = ['code' => $code, 'name' => $name];
                }
                // A category row (no " - ") just resets nothing — it's purely informational,
                // never needed to build a group, so there's nothing to capture from it.

                continue;
            }

            $date = $this->parseDate($colA);

            if ($date === null) {
                $warnings[] = "Baris tidak dikenali (kolom A=\"{$colA}\", kolom B=\"{$colB}\") — dilewati.";

                continue;
            }

            if ($currentItem === null) {
                $warnings[] = "Baris transaksi \"{$colB}\" ditemukan sebelum ada baris item — dilewati.";

                continue;
            }

            $get = fn (int $i) => DataCleaner::normalizeNumber($row[$i] ?? null, $decimalStyle) ?? 0.0;
            $total = $get(5);
            $cn = $get(6);
            $netAmt = $get(7);
            $netQty = $get(10);

            if (abs(($total - $cn) - $netAmt) > self::AMOUNT_EPSILON) {
                $warnings[] = sprintf(
                    '%s (%s): NET AMT (Rp %s) tidak sama dengan TOTAL - CN (Rp %s) — data tetap diproses.',
                    $colB, $currentItem['name'], number_format($netAmt, 0, ',', '.'), number_format($total - $cn, 0, ',', '.'),
                );
            }

            $flatRows[] = [
                'document_number' => $colB,
                'date' => $date,
                'supplier_name' => $colC,
                'item_code' => $currentItem['code'],
                'item_name' => $currentItem['name'],
                'qty' => $netQty,
                // NET QTY of 0 with a real NET AMT would be a data anomaly (not observed in the
                // real file) — treated as rate 0 rather than a division error, same posture as a
                // genuine 0-qty/0-amount "DO-...BONUS" line (real, valid, zero-value goods).
                'rate' => $netQty > self::AMOUNT_EPSILON ? round($netAmt / $netQty, 2) : 0.0,
            ];
        }

        $groups = [];
        foreach ($flatRows as $row) {
            $key = $row['document_number'];

            if (! isset($groups[$key])) {
                $groups[$key] = ['document_number' => $key, 'date' => $row['date'], 'supplier_name' => $row['supplier_name'], 'items' => []];
            }

            $groups[$key]['items'][] = ['item_code' => $row['item_code'], 'item_name' => $row['item_name'], 'qty' => $row['qty'], 'rate' => $row['rate']];
        }

        return ['groups' => array_values($groups), 'warnings' => $warnings];
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

    private function parseDate(?string $value): ?string
    {
        return $value === null ? null : DataCleaner::normalizeDate($value);
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : (string) $value;
    }
}
