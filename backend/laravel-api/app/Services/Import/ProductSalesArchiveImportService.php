<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Models\ProductSalesSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parses the Skybiz "13 Product Sales Report - Detail" export (group by item code - detail
 * variant) into a standalone snapshot -- item rows (subtotal: columns A-C = code/description/
 * group, position-based since an item row carries no header of its own; its own Subtotal Qty/
 * Base Qty/Amount then land in the SAME columns as the detail rows' QTY/BASE QTY/AMOUNT EXC.
 * TAX header slots -- confirmed against a real export, looked up by header name like everything
 * else) alternate with detail rows (column A is a date). Detail rows inherit item_code/
 * item_description/item_group from the item row they sat under; item rows are subtotals and
 * never stored as lines themselves -- same "recompute, don't trust the file's own subtotal"
 * rule as CustomerOutstandingArchiveImportService, but checked per item instead of per customer.
 * A trailing "TOTAL" row (shaped like a detail row, "TOTAL" sitting in the CUSTOMER NAME slot)
 * ends the file -- also confirmed against a real export.
 *
 * Same period-stacking commit posture as SalesListingArchiveImportService.
 */
class ProductSalesArchiveImportService
{
    private const EPSILON = 0.01;

    private const REQUIRED_DETAIL_COLUMNS = ['DOCUMENT #', 'QTY', 'AMOUNT EXC. TAX'];

    /**
     * @return array{
     *   period_start: string, period_end: string, company_name: ?string,
     *   total_rows: int, total_items: int,
     *   grand_total_qty: float, grand_total_amount_excl_tax: float,
     *   failed_rows: array<int, array{row: int, reason: string}>,
     *   subtotal_mismatches: array<int, array{item_code: string, item_description: string, row: int, file_qty: float, file_amount: float, computed_qty: float, computed_amount: float}>,
     *   grand_total_mismatch: ?array{file_amount: float, computed_amount: float},
     * }
     */
    public function preflight(string $absolutePath, string $extension): array
    {
        $parsed = $this->parse($absolutePath, $extension);

        return [
            'period_start' => $parsed['period_start'],
            'period_end' => $parsed['period_end'],
            'company_name' => $parsed['company_name'],
            'total_rows' => count($parsed['lines']),
            'total_items' => $parsed['item_count'],
            'grand_total_qty' => $parsed['sum_qty'],
            'grand_total_amount_excl_tax' => $parsed['sum_amount'],
            'failed_rows' => $parsed['failed_rows'],
            'subtotal_mismatches' => $parsed['subtotal_mismatches'],
            'grand_total_mismatch' => $parsed['grand_total_mismatch'],
        ];
    }

    public function commit(string $absolutePath, string $extension, string $originalFilename, ?string $importedBy): ProductSalesSnapshot
    {
        $parsed = $this->parse($absolutePath, $extension);

        return DB::transaction(function () use ($parsed, $originalFilename, $importedBy) {
            ProductSalesSnapshot::query()
                ->where('period_start', $parsed['period_start'])
                ->where('period_end', $parsed['period_end'])
                ->get()
                ->each(fn (ProductSalesSnapshot $existing) => $existing->delete());

            $snapshot = ProductSalesSnapshot::query()->create([
                'period_start' => $parsed['period_start'],
                'period_end' => $parsed['period_end'],
                'source_filename' => $originalFilename,
                'company_name' => $parsed['company_name'],
                'total_rows' => count($parsed['lines']),
                'total_items' => $parsed['item_count'],
                'grand_total_qty' => $parsed['sum_qty'],
                'grand_total_amount_excl_tax' => $parsed['sum_amount'],
                'imported_by' => $importedBy,
            ]);

            $now = now();
            foreach (array_chunk($parsed['lines'], 500) as $chunk) {
                DB::table('product_sales_snapshot_lines')->insert(array_map(fn ($line) => [
                    'id' => (string) Str::uuid(),
                    'snapshot_id' => $snapshot->id,
                    ...$line,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            return $snapshot;
        });
    }

    /** @return array{period_start: string, period_end: string, company_name: ?string, lines: array, item_count: int, sum_qty: float, sum_amount: float, failed_rows: array, subtotal_mismatches: array, grand_total_mismatch: ?array} */
    private function parse(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (count($rawRows) < 6) {
            throw new BusinessException('File terlalu pendek untuk format "13 Product Sales Report - Detail".');
        }

        $this->assertIsRightFileType($rawRows[0][0] ?? null);
        $companyName = DataCleaner::blankToNull($rawRows[1][0] ?? null);
        [$periodStart, $periodEnd] = $this->extractPeriod($rawRows[2] ?? []);

        $headerRowIndex = ImportFileReader::findHeaderRowByCell($rawRows, 'DOCUMENT #');
        $columnIndex = $this->mapColumns($rawRows[$headerRowIndex]);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'company_name' => $companyName,
            ...$this->parseBody(array_slice($rawRows, $headerRowIndex + 1), $headerRowIndex + 2, $columnIndex),
        ];
    }

    private function assertIsRightFileType(mixed $titleCell): void
    {
        $title = is_string($titleCell) ? $titleCell : '';

        if (stripos($title, 'PRODUCT SALES REPORT') !== false) {
            return;
        }

        if (stripos($title, 'SALES LISTING') !== false) {
            throw new BusinessException('File yang diupload adalah "01 Sales Listing", bukan "13 Product Sales Report". Pilih jenis file yang sesuai.');
        }

        throw new BusinessException('File ini bukan format "13 Product Sales Report - Detail" -- baris judul tidak sesuai. Periksa kembali file yang diupload.');
    }

    /** @return array{string, string} [period_start, period_end] as Y-m-d */
    private function extractPeriod(array $row): array
    {
        $joined = implode(' ', array_map(fn ($v) => is_string($v) ? $v : '', $row));

        if (! preg_match('/(\d{2}\/\d{2}\/\d{4})(?:\s+\d{2}:\d{2}:\d{2})?\s*-\s*(\d{2}\/\d{2}\/\d{4})/', $joined, $m)) {
            throw new BusinessException('Tidak menemukan periode (dd/MM/yyyy - dd/MM/yyyy) pada baris ke-3 file.');
        }

        $start = DataCleaner::normalizeDate($m[1]);
        $end = DataCleaner::normalizeDate($m[2]);

        if ($start === null || $end === null) {
            throw new BusinessException("Periode tidak valid: {$m[1]} - {$m[2]}.");
        }

        return [$start, $end];
    }

    /** @return array<string, int> header name (trimmed) => column index */
    private function mapColumns(array $headerRow): array
    {
        $columnIndex = [];
        foreach ($headerRow as $i => $cell) {
            if (is_string($cell) && trim($cell) !== '') {
                $columnIndex[trim($cell)] = $i;
            }
        }

        $missing = array_diff(self::REQUIRED_DETAIL_COLUMNS, array_keys($columnIndex));
        if ($missing !== []) {
            throw new BusinessException('Header kolom tidak lengkap, kolom hilang: '.implode(', ', $missing).'.');
        }

        return $columnIndex;
    }

    /** @return array{lines: array, item_count: int, sum_qty: float, sum_amount: float, failed_rows: array, subtotal_mismatches: array, grand_total_mismatch: ?array} */
    private function parseBody(array $bodyRows, int $firstRowNo, array $col): array
    {
        $lines = [];
        $failedRows = [];
        $subtotalMismatches = [];
        $currentItem = null;
        $accumQty = 0.0;
        $accumBaseQty = 0.0;
        $accumAmount = 0.0;
        $itemCount = 0;
        $itemSubtotalAmountSum = 0.0;

        $finalizeItem = function () use (&$currentItem, &$accumQty, &$accumBaseQty, &$accumAmount, &$subtotalMismatches) {
            if ($currentItem === null) {
                return;
            }
            if (abs($currentItem['subtotal_qty'] - $accumQty) > self::EPSILON || abs($currentItem['subtotal_amount'] - $accumAmount) > self::EPSILON) {
                $subtotalMismatches[] = [
                    'item_code' => $currentItem['code'],
                    'item_description' => $currentItem['description'],
                    'row' => $currentItem['row'],
                    'file_qty' => $currentItem['subtotal_qty'],
                    'file_amount' => $currentItem['subtotal_amount'],
                    'computed_qty' => $accumQty,
                    'computed_amount' => $accumAmount,
                ];
            }
        };

        foreach ($bodyRows as $i => $row) {
            $rowNo = $firstRowNo + $i;
            $colA = DataCleaner::blankToNull($row[0] ?? null);

            if ($colA === null && DataCleaner::blankToNull($row[1] ?? null) === null && $this->allBlank($row)) {
                continue; // fully blank separator row
            }

            if (is_string($colA) && str_starts_with(trim($colA), 'Printed By')) {
                break;
            }

            $asDate = $colA !== null ? DataCleaner::normalizeDate((string) $colA) : null;

            if ($asDate !== null) {
                // Detail row.
                if ($currentItem === null) {
                    $failedRows[] = ['row' => $rowNo, 'reason' => 'Baris detail ditemukan sebelum ada baris item.'];

                    continue;
                }

                $documentNumber = DataCleaner::blankToNull($row[$col['DOCUMENT #']] ?? null);
                if ($documentNumber === null) {
                    $failedRows[] = ['row' => $rowNo, 'reason' => 'DOCUMENT # kosong pada baris detail.'];

                    continue;
                }

                $qty = DataCleaner::normalizeNumber($row[$col['QTY']] ?? null) ?? 0.0;
                $baseQty = isset($col['BASE QTY']) ? (DataCleaner::normalizeNumber($row[$col['BASE QTY']] ?? null) ?? $qty) : $qty;
                $amount = DataCleaner::normalizeNumber($row[$col['AMOUNT EXC. TAX']] ?? null) ?? 0.0;
                $tax = isset($col['TAX']) ? DataCleaner::normalizeNumber($row[$col['TAX']] ?? null) : null;
                // A real export was seen to just call this column "AMOUNT" (not "AMOUNT INCL. TAX") --
                // confirmed to mean amount-including-tax (AMOUNT EXC. TAX + TAX = AMOUNT exactly).
                $amountInclColumn = $col['AMOUNT INCL. TAX'] ?? $col['AMOUNT'] ?? null;
                $amountIncl = $amountInclColumn !== null ? DataCleaner::normalizeNumber($row[$amountInclColumn] ?? null) : null;
                $customerCode = isset($col['CUSTOMER #']) ? DataCleaner::blankToNull($row[$col['CUSTOMER #']] ?? null) : null;
                $customerName = isset($col['CUSTOMER NAME']) ? DataCleaner::blankToNull($row[$col['CUSTOMER NAME']] ?? null) : null;
                $detailItemGroup = isset($col['ITEM GROUP']) ? DataCleaner::blankToNull($row[$col['ITEM GROUP']] ?? null) : null;

                $accumQty += $qty;
                $accumBaseQty += $baseQty;
                $accumAmount += $amount;

                $lines[] = [
                    'txn_date' => $asDate,
                    'document_number' => trim((string) $documentNumber),
                    'item_code' => $currentItem['code'],
                    'item_description' => $currentItem['description'],
                    'item_group' => $detailItemGroup ?? $currentItem['group'],
                    'customer_code' => $customerCode !== null ? trim((string) $customerCode) : null,
                    'customer_name' => $customerName !== null ? trim((string) $customerName) : null,
                    'qty' => $qty,
                    'base_qty' => $baseQty,
                    'amount_excl_tax' => $amount,
                    'tax' => $tax,
                    'amount_incl_tax' => $amountIncl,
                ];

                continue;
            }

            // Trailing "TOTAL" row (shaped like a detail row with "TOTAL" in the CUSTOMER NAME
            // slot -- confirmed against a real export): the file ends here, never a failed row.
            if ($this->isTotalRow($row)) {
                break;
            }

            if ($colA === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => 'Baris tidak dikenali (bukan baris item maupun detail).'];

                continue;
            }

            // Item (subtotal) row -- finalize the previous item's check before starting a new one.
            $finalizeItem();

            // The item's own identity (code/description/group) has no header of its own, so those
            // 3 stay position-based (columns A-C) -- but confirmed against a real export, its
            // Subtotal Qty/Base Qty/Amount actually land in the SAME columns as the detail rows'
            // own QTY/BASE QTY/AMOUNT EXC. TAX (customer #/name just sit blank in between), not at
            // a fixed D/E/F offset -- looked up by header name like everything else, not guessed.
            $description = DataCleaner::blankToNull($row[1] ?? null) ?? '';
            $group = DataCleaner::blankToNull($row[2] ?? null);
            $subtotalQty = DataCleaner::normalizeNumber($row[$col['QTY']] ?? null) ?? 0.0;
            $subtotalBaseQty = isset($col['BASE QTY']) ? (DataCleaner::normalizeNumber($row[$col['BASE QTY']] ?? null) ?? 0.0) : $subtotalQty;
            $subtotalAmount = DataCleaner::normalizeNumber($row[$col['AMOUNT EXC. TAX']] ?? null) ?? 0.0;

            $currentItem = [
                'code' => trim((string) $colA),
                'description' => (string) $description,
                'group' => $group !== null ? (string) $group : null,
                'subtotal_qty' => $subtotalQty,
                'subtotal_base_qty' => $subtotalBaseQty,
                'subtotal_amount' => $subtotalAmount,
                'row' => $rowNo,
            ];
            $accumQty = 0.0;
            $accumBaseQty = 0.0;
            $accumAmount = 0.0;
            $itemCount++;
            $itemSubtotalAmountSum += $subtotalAmount;
        }

        $finalizeItem();

        $sumAmountComputed = round(array_sum(array_column($lines, 'amount_excl_tax')), 2);
        $itemSubtotalAmountSum = round($itemSubtotalAmountSum, 2);

        $grandTotalMismatch = null;
        if (abs($sumAmountComputed - $itemSubtotalAmountSum) > self::EPSILON) {
            $grandTotalMismatch = [
                'file_amount' => $itemSubtotalAmountSum,
                'computed_amount' => $sumAmountComputed,
            ];
        }

        return [
            'lines' => $lines,
            'item_count' => $itemCount,
            'sum_qty' => round(array_sum(array_column($lines, 'qty')), 2),
            'sum_amount' => $sumAmountComputed,
            'failed_rows' => $failedRows,
            'subtotal_mismatches' => $subtotalMismatches,
            'grand_total_mismatch' => $grandTotalMismatch,
        ];
    }

    private function isTotalRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (is_string($cell) && strtoupper(trim($cell)) === 'TOTAL') {
                return true;
            }
        }

        return false;
    }

    private function allBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (! (DataCleaner::blankToNull($cell) === null)) {
                return false;
            }
        }

        return true;
    }
}
