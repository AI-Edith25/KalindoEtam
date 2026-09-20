<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Models\SupplierOutstandingSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AP mirror of CustomerOutstandingArchiveImportService, for the legacy "Supplier Outstanding
 * Bills" export -- same file layout (title/company/"Date as at"/header rows, "Supplier : <code>
 * - <name>" group markers, per-supplier subtotal row, trailing Grand Total, "Printed By :"),
 * same preflight -> commit shape, same soft-fail-row philosophy. See that class's own docblock
 * for the shared rules; this one documents only what's genuinely different.
 *
 * File-type detection deliberately does NOT require an exact title match (Skybiz's internal
 * title text is known to differ from its menu name and drift between versions) -- it accepts
 * either the filename or the first row's own text naming "Supplier"+"Bills", checked BEFORE an
 * explicit reject for anything that looks like the customer file instead (wrong page). Column
 * lookup is by HEADER NAME, not a fixed position, since a supplier export's exact wording for
 * columns beyond "Ref. No"/"Unpaid Amount" isn't confirmed against a real sample the way the
 * customer file was.
 */
class SupplierOutstandingArchiveImportService
{
    private const EPSILON = 0.01;

    /**
     * @return array{
     *   company_name: ?string, snapshot_as_of_date: string, total_rows: int, total_suppliers: int,
     *   total_unpaid: float, total_overdue: float,
     *   failed_rows: array<int, array{row: int, reason: string}>,
     *   subtotal_mismatches: array<int, array{supplier_code: string, supplier_name: string, row: int, file_unpaid: ?float, file_overdue: ?float, computed_unpaid: float, computed_overdue: float}>,
     *   grand_total_mismatch: ?array{file_unpaid: float, file_overdue: float, computed_unpaid: float, computed_overdue: float},
     * }
     */
    public function preflight(string $absolutePath, string $extension, string $originalFilename): array
    {
        $parsed = $this->parse($absolutePath, $extension, $originalFilename);

        return [
            'company_name' => $parsed['company_name'],
            'snapshot_as_of_date' => $parsed['snapshot_as_of_date'],
            'total_rows' => count($parsed['lines']),
            'total_suppliers' => $parsed['supplier_count'],
            'total_unpaid' => $parsed['sum_unpaid'],
            'total_overdue' => $parsed['sum_overdue'],
            'failed_rows' => $parsed['failed_rows'],
            'subtotal_mismatches' => $parsed['subtotal_mismatches'],
            'grand_total_mismatch' => $parsed['grand_total_mismatch'],
        ];
    }

    public function commit(string $absolutePath, string $extension, string $originalFilename, ?string $importedBy): SupplierOutstandingSnapshot
    {
        $parsed = $this->parse($absolutePath, $extension, $originalFilename);

        return DB::transaction(function () use ($parsed, $originalFilename, $importedBy) {
            $snapshot = SupplierOutstandingSnapshot::query()->create([
                'source_filename' => $originalFilename,
                'company_name' => $parsed['company_name'],
                'snapshot_as_of_date' => $parsed['snapshot_as_of_date'],
                'total_rows' => count($parsed['lines']),
                'total_suppliers' => $parsed['supplier_count'],
                'grand_total_unpaid' => $parsed['sum_unpaid'],
                'grand_total_overdue' => $parsed['sum_overdue'],
                'imported_by' => $importedBy,
            ]);

            $now = now();
            foreach (array_chunk($parsed['lines'], 500) as $chunk) {
                DB::table('supplier_outstanding_snapshot_lines')->insert(array_map(fn ($line) => [
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

    /** @return array{company_name: ?string, snapshot_as_of_date: string, lines: array, sum_unpaid: float, sum_overdue: float, supplier_count: int, failed_rows: array, subtotal_mismatches: array, grand_total_mismatch: ?array} */
    private function parse(string $absolutePath, string $extension, string $originalFilename): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (count($rawRows) < 6) {
            throw new BusinessException('File terlalu pendek untuk format "Supplier Outstanding Bills".');
        }

        $this->assertIsRightFileType($rawRows[0][0] ?? null, $originalFilename);

        $companyName = DataCleaner::blankToNull($rawRows[1][0] ?? null);
        $asOfDate = $this->extractAsOfDate($rawRows[2][0] ?? null);
        $headerRowIndex = $this->findHeaderRow($rawRows);
        $columns = $this->mapColumns($rawRows[$headerRowIndex]);

        return [
            'company_name' => $companyName,
            'snapshot_as_of_date' => $asOfDate,
            ...$this->parseBody(array_slice($rawRows, $headerRowIndex + 1), $headerRowIndex + 2, $columns),
        ];
    }

    /**
     * Priority order matters: a file that looks like the CUSTOMER export is rejected with a
     * "wrong page" message before the supplier-acceptance check even runs -- otherwise a
     * customer file whose first row happens to mention neither "Supplier" nor "Bills" would
     * fall through to the generic "format not recognized" message instead of the specific,
     * actionable one.
     */
    private function assertIsRightFileType(mixed $titleCell, string $originalFilename): void
    {
        $titleText = is_string($titleCell) ? $titleCell : '';

        $looksLikeCustomerFile = stripos($originalFilename, 'CustomerOutstandingBills') !== false || stripos($titleText, 'Customer') !== false;
        if ($looksLikeCustomerFile) {
            throw new BusinessException('File ini adalah data piutang customer. Silakan import melalui halaman Reports > AR Detail.');
        }

        $looksLikeSupplierFile = stripos($originalFilename, 'SupplierOutstandingBills') !== false
            || (stripos($titleText, 'Supplier') !== false && stripos($titleText, 'Bills') !== false);
        if (! $looksLikeSupplierFile) {
            throw new BusinessException('Format file tidak dikenali. Pastikan file berasal dari menu Supplier Outstanding Bills di Skybiz.');
        }
    }

    private function extractAsOfDate(mixed $cell): string
    {
        if (! is_string($cell) || ! preg_match('/Date as at\s*:\s*(\d{2}\/\d{2}\/\d{4})/i', $cell, $m)) {
            throw new BusinessException('Tidak menemukan "Date as at : dd/mm/yyyy" di baris ke-3 file -- periksa kembali formatnya.');
        }

        return DataCleaner::normalizeDate($m[1]) ?? throw new BusinessException("Tanggal snapshot tidak valid: {$m[1]}.");
    }

    private function findHeaderRow(array $rawRows): int
    {
        foreach (array_slice($rawRows, 0, 15) as $i => $row) {
            foreach ($row as $cell) {
                if (is_string($cell) && trim($cell) === 'Ref. No') {
                    return $i;
                }
            }
        }

        throw new BusinessException('Format file tidak dikenali. Pastikan file berasal dari menu Supplier Outstanding Bills di Skybiz.');
    }

    /** @return array<string, int> column name => raw index */
    private function mapColumns(array $headerRow): array
    {
        $map = [];
        foreach (array_values($headerRow) as $index => $cell) {
            if (is_string($cell) && trim($cell) !== '') {
                $map[trim($cell)] = $index;
            }
        }

        if (! isset($map['Ref. No']) || ! isset($map['Unpaid Amount'])) {
            throw new BusinessException('Format file tidak dikenali. Pastikan file berasal dari menu Supplier Outstanding Bills di Skybiz.');
        }

        return $map;
    }

    /** @return array{lines: array, sum_unpaid: float, sum_overdue: float, supplier_count: int, failed_rows: array, subtotal_mismatches: array, grand_total_mismatch: ?array} */
    private function parseBody(array $bodyRows, int $firstRowNo, array $columns): array
    {
        $col = fn (array $row, string $name) => isset($columns[$name]) ? ($row[$columns[$name]] ?? null) : null;

        $lines = [];
        $failedRows = [];
        $subtotalMismatches = [];
        $currentCode = null;
        $currentName = null;
        $supplierUnpaidSum = 0.0;
        $supplierOverdueSum = 0.0;
        $supplierCount = 0;
        $grandTotalUnpaid = null;
        $grandTotalOverdue = null;

        foreach ($bodyRows as $i => $row) {
            $rowNo = $firstRowNo + $i;
            $colA = DataCleaner::blankToNull($col($row, 'Date'));
            $colRef = DataCleaner::blankToNull($col($row, 'Ref. No'));
            $colUnpaid = DataCleaner::normalizeNumber($col($row, 'Unpaid Amount'));
            $colOverdue = DataCleaner::normalizeNumber($col($row, 'Overdue Amount'));

            if ($colA === null && $colRef === null && $colUnpaid === null && $colOverdue === null) {
                continue; // fully blank separator row
            }

            if (is_string($colA) && str_starts_with($colA, 'Printed By')) {
                break; // end of file
            }

            if (is_string($colA) && trim($colA) === 'Grand Total') {
                $grandTotalUnpaid = $colUnpaid;
                $grandTotalOverdue = $colOverdue;

                continue;
            }

            if (is_string($colA) && preg_match('/^Supplier\s*:\s*(\S+)\s*-\s*(.+)$/u', trim($colA), $m)) {
                if ($currentCode !== null) {
                    $subtotalMismatches[] = [
                        'supplier_code' => $currentCode,
                        'supplier_name' => $currentName,
                        'row' => $rowNo,
                        'file_unpaid' => null,
                        'file_overdue' => null,
                        'computed_unpaid' => $supplierUnpaidSum,
                        'computed_overdue' => $supplierOverdueSum,
                    ];
                }

                $currentCode = trim($m[1]);
                $currentName = trim($m[2]);
                $supplierUnpaidSum = 0.0;
                $supplierOverdueSum = 0.0;
                $supplierCount++;

                continue;
            }

            // Subtotal row: Date & Ref. No blank, Unpaid Amount & Overdue Amount filled.
            if ($colA === null && $colRef === null && $colUnpaid !== null && $colOverdue !== null) {
                if ($currentCode === null) {
                    $failedRows[] = ['row' => $rowNo, 'reason' => 'Baris subtotal ditemukan sebelum ada baris "Supplier : ...".'];

                    continue;
                }
                if (abs($supplierUnpaidSum - $colUnpaid) > self::EPSILON || abs($supplierOverdueSum - $colOverdue) > self::EPSILON) {
                    $subtotalMismatches[] = [
                        'supplier_code' => $currentCode,
                        'supplier_name' => $currentName,
                        'row' => $rowNo,
                        'file_unpaid' => $colUnpaid,
                        'file_overdue' => $colOverdue,
                        'computed_unpaid' => $supplierUnpaidSum,
                        'computed_overdue' => $supplierOverdueSum,
                    ];
                }
                $currentCode = null;

                continue;
            }

            // Real transaction row.
            if ($currentCode === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => 'Baris transaksi ditemukan di luar grup "Supplier : ...".'];

                continue;
            }
            if ($colRef === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => 'Ref. No kosong.'];

                continue;
            }

            $txnDate = $colA !== null ? DataCleaner::normalizeDate((string) $colA) : null;
            $dueDateRaw = DataCleaner::blankToNull($col($row, 'Due Date'));
            $dueDate = $dueDateRaw !== null ? DataCleaner::normalizeDate((string) $dueDateRaw) : null;

            if ($txnDate === null || $dueDate === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => "Tanggal tidak valid (Date: \"{$colA}\", Due Date: \"{$dueDateRaw}\")."];

                continue;
            }

            $invoiceAmount = DataCleaner::normalizeNumber($col($row, 'Invoice Amt')) ?? 0.0;
            $paidAmount = DataCleaner::normalizeNumber($col($row, 'Paid Amount')) ?? 0.0;
            $unpaidAmount = $colUnpaid ?? 0.0;
            $termsDays = DataCleaner::normalizeNumber($col($row, 'Terms (Days)'));
            $overdueAmount = $colOverdue ?? 0.0;
            $overdueDays = (int) (DataCleaner::normalizeNumber($col($row, 'Overdue (Days)')) ?? 0);

            $supplierUnpaidSum += $unpaidAmount;
            $supplierOverdueSum += $overdueAmount;

            $lines[] = [
                'supplier_code' => $currentCode,
                'supplier_name' => $currentName,
                'txn_date' => $txnDate,
                'ref_no' => trim((string) $colRef),
                'invoice_amount' => $invoiceAmount,
                'paid_amount' => $paidAmount,
                'unpaid_amount' => $unpaidAmount,
                'terms_days' => $termsDays !== null ? (int) $termsDays : null,
                'due_date' => $dueDate,
                'overdue_amount' => $overdueAmount,
                'overdue_days' => $overdueDays,
            ];
        }

        if ($grandTotalUnpaid === null) {
            throw new BusinessException('Baris "Grand Total" tidak ditemukan di file.');
        }

        $sumUnpaid = round(array_sum(array_column($lines, 'unpaid_amount')), 2);
        $sumOverdue = round(array_sum(array_column($lines, 'overdue_amount')), 2);

        $grandTotalMismatch = null;
        if (abs($sumUnpaid - $grandTotalUnpaid) > self::EPSILON || abs($sumOverdue - $grandTotalOverdue) > self::EPSILON) {
            $grandTotalMismatch = [
                'file_unpaid' => $grandTotalUnpaid,
                'file_overdue' => $grandTotalOverdue,
                'computed_unpaid' => $sumUnpaid,
                'computed_overdue' => $sumOverdue,
            ];
        }

        return [
            'lines' => $lines,
            'sum_unpaid' => $sumUnpaid,
            'sum_overdue' => $sumOverdue,
            'supplier_count' => $supplierCount,
            'failed_rows' => $failedRows,
            'subtotal_mismatches' => $subtotalMismatches,
            'grand_total_mismatch' => $grandTotalMismatch,
        ];
    }
}
