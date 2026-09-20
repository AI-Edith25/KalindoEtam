<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Models\SalesListingSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parses the Skybiz "01 Sales Listing" export (default XLS) into a standalone snapshot --
 * flat, one row per Invoice/Credit Note document, no group or subtotal rows at all (unlike
 * the AR archive or ProductSalesArchiveImportService, this file needs none of that logic).
 *
 * Unlike the single "latest wins" AR/AP archives, Sales Report snapshots stack per period --
 * commit() replaces only the snapshot with the SAME (period_start, period_end), a different
 * period is added alongside the others. See SalesArchiveController::resolve().
 */
class SalesListingArchiveImportService
{
    private const EPSILON = 0.01;

    private const REQUIRED_COLUMNS = [
        'DOCUMENT #',
        'CUSTOMER CODE',
        'CUSTOMER NAME',
        'AMOUNT (EXCLUDE TAX)',
        'AMOUNT (INCLUDE TAX)',
    ];

    /**
     * @return array{
     *   period_start: string, period_end: string, company_name: ?string,
     *   total_rows: int, total_documents: int,
     *   grand_total_amount_excl_tax: float, grand_total_amount_incl_tax: float,
     *   failed_rows: array<int, array{row: int, reason: string}>,
     *   grand_total_mismatch: ?array{file_amount_excl_tax: float, file_amount_incl_tax: float, computed_amount_excl_tax: float, computed_amount_incl_tax: float},
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
            'total_documents' => count($parsed['lines']),
            'grand_total_amount_excl_tax' => $parsed['sum_excl_tax'],
            'grand_total_amount_incl_tax' => $parsed['sum_incl_tax'],
            'failed_rows' => $parsed['failed_rows'],
            'grand_total_mismatch' => $parsed['grand_total_mismatch'],
        ];
    }

    public function commit(string $absolutePath, string $extension, string $originalFilename, ?string $importedBy): SalesListingSnapshot
    {
        $parsed = $this->parse($absolutePath, $extension);

        return DB::transaction(function () use ($parsed, $originalFilename, $importedBy) {
            // Same period replaces, a different period is added -- never "latest wins" globally.
            SalesListingSnapshot::query()
                ->where('period_start', $parsed['period_start'])
                ->where('period_end', $parsed['period_end'])
                ->get()
                ->each(fn (SalesListingSnapshot $existing) => $existing->delete());

            $snapshot = SalesListingSnapshot::query()->create([
                'period_start' => $parsed['period_start'],
                'period_end' => $parsed['period_end'],
                'source_filename' => $originalFilename,
                'company_name' => $parsed['company_name'],
                'total_rows' => count($parsed['lines']),
                'total_documents' => count($parsed['lines']),
                'grand_total_amount_excl_tax' => $parsed['sum_excl_tax'],
                'grand_total_amount_incl_tax' => $parsed['sum_incl_tax'],
                'imported_by' => $importedBy,
            ]);

            $now = now();
            foreach (array_chunk($parsed['lines'], 500) as $chunk) {
                DB::table('sales_listing_snapshot_lines')->insert(array_map(fn ($line) => [
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

    /** @return array{period_start: string, period_end: string, company_name: ?string, lines: array, sum_excl_tax: float, sum_incl_tax: float, failed_rows: array} */
    private function parse(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (count($rawRows) < 6) {
            throw new BusinessException('File terlalu pendek untuk format "01 Sales Listing".');
        }

        $this->assertIsRightFileType($rawRows[0][0] ?? null);
        [$periodStart, $periodEnd] = $this->extractPeriod($rawRows[1] ?? []);
        $companyName = DataCleaner::blankToNull($rawRows[2][0] ?? null);

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

        if (stripos($title, 'SALES LISTING') !== false) {
            return;
        }

        if (stripos($title, 'PRODUCT SALES REPORT') !== false) {
            throw new BusinessException('File yang diupload adalah "13 Product Sales Report", bukan "01 Sales Listing". Pilih jenis file yang sesuai.');
        }

        throw new BusinessException('File ini bukan format "01 Sales Listing" -- baris judul tidak sesuai. Periksa kembali file yang diupload.');
    }

    /** @return array{string, string} [period_start, period_end] as Y-m-d */
    private function extractPeriod(array $row): array
    {
        $joined = implode(' ', array_map(fn ($v) => is_string($v) ? $v : '', $row));

        if (! preg_match('/(\d{2}\/\d{2}\/\d{4})(?:\s+\d{2}:\d{2}:\d{2})?\s*-\s*(\d{2}\/\d{2}\/\d{4})/', $joined, $m)) {
            throw new BusinessException('Tidak menemukan periode (dd/MM/yyyy - dd/MM/yyyy) pada baris ke-2 file.');
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

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columnIndex));
        if ($missing !== []) {
            throw new BusinessException('Header kolom tidak lengkap, kolom hilang: '.implode(', ', $missing).'.');
        }

        return $columnIndex;
    }

    /** @return array{lines: array, sum_excl_tax: float, sum_incl_tax: float, failed_rows: array, grand_total_mismatch: ?array} */
    private function parseBody(array $bodyRows, int $firstRowNo, array $col): array
    {
        $lines = [];
        $failedRows = [];
        $grandTotalExclTax = null;
        $grandTotalInclTax = null;

        foreach ($bodyRows as $i => $row) {
            $rowNo = $firstRowNo + $i;

            $documentNumber = DataCleaner::blankToNull($row[$col['DOCUMENT #']] ?? null);
            $customerCode = DataCleaner::blankToNull($row[$col['CUSTOMER CODE']] ?? null);
            $amountExclTax = DataCleaner::normalizeNumber($row[$col['AMOUNT (EXCLUDE TAX)']] ?? null);

            if ($documentNumber === null && $customerCode === null && $amountExclTax === null) {
                continue; // fully blank trailing row
            }

            if (is_string($documentNumber) && str_starts_with(trim($documentNumber), 'Printed By')) {
                break; // end of file
            }

            // Trailing "TOTAL" row (TYPE column carries the label, not a real file-level Grand
            // Total row -- confirmed against a real export): captured for the grand-total check
            // below, then the file ends -- never counted as a failed row.
            $typeCell = isset($col['TYPE']) ? DataCleaner::blankToNull($row[$col['TYPE']] ?? null) : null;
            if (is_string($typeCell) && strtoupper(trim($typeCell)) === 'TOTAL') {
                $grandTotalExclTax = $amountExclTax;
                $grandTotalInclTax = DataCleaner::normalizeNumber($row[$col['AMOUNT (INCLUDE TAX)']] ?? null);

                break;
            }

            if ($documentNumber === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => 'DOCUMENT # kosong.'];

                continue;
            }

            $dateRaw = isset($col['DATE']) ? DataCleaner::blankToNull($row[$col['DATE']] ?? null) : null;
            $txnDate = $dateRaw !== null ? DataCleaner::normalizeDate((string) $dateRaw) : null;

            if ($txnDate === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => "Tanggal tidak valid (\"{$dateRaw}\")."];

                continue;
            }

            $customerName = DataCleaner::blankToNull($row[$col['CUSTOMER NAME']] ?? null);
            if ($customerCode === null || $customerName === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => 'Customer Code/Name kosong.'];

                continue;
            }

            $lines[] = [
                'txn_date' => $txnDate,
                'document_number' => trim((string) $documentNumber),
                'reference_so' => isset($col['REFERENCE #']) ? DataCleaner::blankToNull($row[$col['REFERENCE #']] ?? null) : null,
                'reference_do' => isset($col['REFERENCE 2 #']) ? DataCleaner::blankToNull($row[$col['REFERENCE 2 #']] ?? null) : null,
                'customer_code' => trim((string) $customerCode),
                'customer_name' => trim((string) $customerName),
                'type_code' => isset($col['TYPE']) ? (string) (DataCleaner::blankToNull($row[$col['TYPE']] ?? null) ?? '') : '',
                'amount_excl_tax' => $amountExclTax ?? 0.0,
                'disc_adjustment' => isset($col['DISC ADJUSTMENT']) ? (DataCleaner::normalizeNumber($row[$col['DISC ADJUSTMENT']] ?? null) ?? 0.0) : 0.0,
                'tax' => isset($col['TAX']) ? (DataCleaner::normalizeNumber($row[$col['TAX']] ?? null) ?? 0.0) : 0.0,
                'amount_incl_tax' => DataCleaner::normalizeNumber($row[$col['AMOUNT (INCLUDE TAX)']] ?? null) ?? 0.0,
            ];
        }

        $sumExclTax = round(array_sum(array_column($lines, 'amount_excl_tax')), 2);
        $sumInclTax = round(array_sum(array_column($lines, 'amount_incl_tax')), 2);

        $grandTotalMismatch = null;
        if ($grandTotalExclTax !== null && $grandTotalInclTax !== null
            && (abs($sumExclTax - $grandTotalExclTax) > self::EPSILON || abs($sumInclTax - $grandTotalInclTax) > self::EPSILON)) {
            $grandTotalMismatch = [
                'file_amount_excl_tax' => $grandTotalExclTax,
                'file_amount_incl_tax' => $grandTotalInclTax,
                'computed_amount_excl_tax' => $sumExclTax,
                'computed_amount_incl_tax' => $sumInclTax,
            ];
        }

        return [
            'lines' => $lines,
            'sum_excl_tax' => $sumExclTax,
            'sum_incl_tax' => $sumInclTax,
            'failed_rows' => $failedRows,
            'grand_total_mismatch' => $grandTotalMismatch,
        ];
    }
}
