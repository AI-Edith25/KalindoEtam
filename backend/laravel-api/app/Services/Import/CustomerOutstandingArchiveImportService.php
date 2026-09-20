<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Models\CustomerOutstandingSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parses the legacy "Customer Unpaid Bills With Overdue Advice" export into an archive snapshot
 * -- a standalone notebook, never joined to or reconciled against the live Sales/Invoice/
 * Customer/AR module (no FK to any of them; customer_code/name are plain snapshotted strings).
 *
 * preflight() -> commit(), same shape as SmartOpeningStockImportService: preflight always runs
 * first and returns a report (rows parsed, customers, totals, any row that couldn't be parsed
 * and why, any subtotal/Grand Total mismatch) that must be shown before anything commits.
 * commit() re-parses from disk rather than trusting cached state.
 *
 * Row-level failures (a bad date, a non-numeric amount, a subtotal that doesn't foot) are
 * reported, not fatal -- only genuinely wrong input (not this file format at all, no "Date as
 * at" line, no header) throws and refuses to even produce a preview. This is a deliberate
 * change from a stricter, all-or-nothing earlier version: a legacy export with a handful of
 * malformed rows should still produce a usable, clearly-flagged import, not an unconditional
 * refusal -- the operator sees exactly what was excluded and why before confirming.
 *
 * Fixed file layout (row numbers are the ticket's own spec, not detected):
 *   Row 1: report title -- must contain "Customer Unpaid Bills" (case-insensitive) or the file
 *   is rejected outright, wrong format entirely. Row 2: company name. Row 3 col A: "Date as at :
 *   dd/mm/yyyy". Row 4: blank. Row 5: column header (located by finding "Ref. No" among its
 *   cells, not a hardcoded row number). Row 6+: "Customer : <code> - <name>" group markers,
 *   transaction rows, a per-customer subtotal row (cols A-D and F-G blank, E and H filled), a
 *   trailing "Grand Total" row, then "Printed By :".
 */
class CustomerOutstandingArchiveImportService
{
    private const EPSILON = 0.01;

    private const EXPECTED_HEADER = ['Date', 'Ref. No', 'Invoice Amt', 'Paid Amount', 'Unpaid Amount', 'Terms (Days)', 'Due Date', 'Overdue Amount', 'Overdue (Days)'];

    /**
     * @return array{
     *   company_name: ?string, snapshot_as_of_date: string, total_rows: int, total_customers: int,
     *   total_unpaid: float, total_overdue: float,
     *   failed_rows: array<int, array{row: int, reason: string}>,
     *   subtotal_mismatches: array<int, array{customer_code: string, customer_name: string, row: int, file_unpaid: float, file_overdue: float, computed_unpaid: float, computed_overdue: float}>,
     *   grand_total_mismatch: ?array{file_unpaid: float, file_overdue: float, computed_unpaid: float, computed_overdue: float},
     * }
     */
    public function preflight(string $absolutePath, string $extension): array
    {
        $parsed = $this->parse($absolutePath, $extension);

        return [
            'company_name' => $parsed['company_name'],
            'snapshot_as_of_date' => $parsed['snapshot_as_of_date'],
            'total_rows' => count($parsed['lines']),
            'total_customers' => $parsed['customer_count'],
            'total_unpaid' => $parsed['sum_unpaid'],
            'total_overdue' => $parsed['sum_overdue'],
            'failed_rows' => $parsed['failed_rows'],
            'subtotal_mismatches' => $parsed['subtotal_mismatches'],
            'grand_total_mismatch' => $parsed['grand_total_mismatch'],
        ];
    }

    public function commit(string $absolutePath, string $extension, string $originalFilename, ?string $importedBy): CustomerOutstandingSnapshot
    {
        $parsed = $this->parse($absolutePath, $extension);

        return DB::transaction(function () use ($parsed, $originalFilename, $importedBy) {
            $snapshot = CustomerOutstandingSnapshot::query()->create([
                'source_filename' => $originalFilename,
                'company_name' => $parsed['company_name'],
                'snapshot_as_of_date' => $parsed['snapshot_as_of_date'],
                'total_rows' => count($parsed['lines']),
                'total_customers' => $parsed['customer_count'],
                'grand_total_unpaid' => $parsed['sum_unpaid'],
                'grand_total_overdue' => $parsed['sum_overdue'],
                'imported_by' => $importedBy,
            ]);

            $now = now();
            foreach (array_chunk($parsed['lines'], 500) as $chunk) {
                DB::table('customer_outstanding_snapshot_lines')->insert(array_map(fn ($line) => [
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

    /** @return array{company_name: ?string, snapshot_as_of_date: string, lines: array, sum_unpaid: float, sum_overdue: float, customer_count: int, failed_rows: array, subtotal_mismatches: array, grand_total_mismatch: ?array} */
    private function parse(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (count($rawRows) < 6) {
            throw new BusinessException('File terlalu pendek untuk format "Customer Unpaid Bills With Overdue Advice".');
        }

        $this->assertIsRightFileType($rawRows[0][0] ?? null);

        $companyName = DataCleaner::blankToNull($rawRows[1][0] ?? null);
        $asOfDate = $this->extractAsOfDate($rawRows[2][0] ?? null);
        $headerRowIndex = $this->findHeaderRow($rawRows);
        $this->assertHeaderMatches($rawRows[$headerRowIndex]);

        return [
            'company_name' => $companyName,
            'snapshot_as_of_date' => $asOfDate,
            ...$this->parseBody(array_slice($rawRows, $headerRowIndex + 1), $headerRowIndex + 2),
        ];
    }

    private function assertIsRightFileType(mixed $titleCell): void
    {
        if (! is_string($titleCell) || stripos($titleCell, 'Customer Unpaid Bills') === false) {
            throw new BusinessException('File ini bukan format "Customer Unpaid Bills With Overdue Advice" -- baris judul tidak sesuai. Periksa kembali file yang diupload.');
        }
    }

    private function extractAsOfDate(mixed $cell): string
    {
        if (! is_string($cell) || ! preg_match('/Date as at\s*:\s*(\d{2}\/\d{2}\/\d{4})/i', $cell, $m)) {
            throw new BusinessException('Tidak menemukan "Date as at : dd/mm/yyyy" di baris ke-3 file -- periksa kembali formatnya.');
        }

        return DataCleaner::normalizeDate($m[1]) ?? throw new BusinessException("Tanggal snapshot tidak valid: {$m[1]}.");
    }

    /** Located by content ("Ref. No" among its cells), not a hardcoded row number -- the ticket's own explicit requirement, since real exports don't always keep title/company on exactly 2 lines. */
    private function findHeaderRow(array $rawRows): int
    {
        foreach (array_slice($rawRows, 0, 15) as $i => $row) {
            foreach ($row as $cell) {
                if (is_string($cell) && trim($cell) === 'Ref. No') {
                    return $i;
                }
            }
        }

        throw new BusinessException('Tidak menemukan baris header (kolom "Ref. No") di 15 baris pertama file.');
    }

    private function assertHeaderMatches(array $headerRow): void
    {
        $actual = array_map(fn ($v) => trim((string) DataCleaner::blankToNull($v)), array_slice(array_values($headerRow), 0, 9));

        if ($actual !== self::EXPECTED_HEADER) {
            throw new BusinessException('Header kolom tidak sesuai format yang diharapkan ('.implode(' | ', self::EXPECTED_HEADER).') -- periksa kembali file sumber.');
        }
    }

    /** @return array{lines: array, sum_unpaid: float, sum_overdue: float, customer_count: int, failed_rows: array, subtotal_mismatches: array, grand_total_mismatch: ?array} */
    private function parseBody(array $bodyRows, int $firstRowNo): array
    {
        $lines = [];
        $failedRows = [];
        $subtotalMismatches = [];
        $currentCode = null;
        $currentName = null;
        $customerUnpaidSum = 0.0;
        $customerOverdueSum = 0.0;
        $customerCount = 0;
        $grandTotalUnpaid = null;
        $grandTotalOverdue = null;

        foreach ($bodyRows as $i => $row) {
            $rowNo = $firstRowNo + $i;
            $colA = DataCleaner::blankToNull($row[0] ?? null);
            $colB = DataCleaner::blankToNull($row[1] ?? null);
            // Indonesian number format ("6.225.000,27" = dot thousands, comma decimal) is this
            // app's established default (DataCleaner::normalizeNumber's own default) -- a genuine
            // Excel numeric cell short-circuits this entirely regardless, only a text-formatted
            // cell (e.g. a CSV variant of this export) is actually affected by the style choice.
            $colE = DataCleaner::normalizeNumber($row[4] ?? null);
            $colH = DataCleaner::normalizeNumber($row[7] ?? null);

            if ($colA === null && $colB === null && $colE === null && $colH === null) {
                continue; // fully blank separator row
            }

            if (is_string($colA) && str_starts_with($colA, 'Printed By')) {
                break; // end of file
            }

            if (is_string($colA) && trim($colA) === 'Grand Total') {
                $grandTotalUnpaid = $colE;
                $grandTotalOverdue = $colH;

                continue;
            }

            if (is_string($colA) && preg_match('/^Customer\s*:\s*(\S+)\s*-\s*(.+)$/u', trim($colA), $m)) {
                // A new group marker while $currentCode is still set means the previous
                // customer's subtotal row was missing entirely -- soft warning, not fatal.
                if ($currentCode !== null) {
                    $subtotalMismatches[] = [
                        'customer_code' => $currentCode,
                        'customer_name' => $currentName,
                        'row' => $rowNo,
                        'file_unpaid' => null,
                        'file_overdue' => null,
                        'computed_unpaid' => $customerUnpaidSum,
                        'computed_overdue' => $customerOverdueSum,
                    ];
                }

                $currentCode = trim($m[1]);
                $currentName = trim($m[2]);
                $customerUnpaidSum = 0.0;
                $customerOverdueSum = 0.0;
                $customerCount++;

                continue;
            }

            // Subtotal row: A-D and F-G blank, E and H filled. Always dropped from the
            // imported lines (never stored) -- checked against what was actually parsed, then
            // discarded, matching Perincian Piutang's own "recompute, don't trust the file's
            // subtotal" rule.
            if ($colA === null && $colB === null && $colE !== null && $colH !== null) {
                if ($currentCode === null) {
                    $failedRows[] = ['row' => $rowNo, 'reason' => 'Baris subtotal ditemukan sebelum ada baris "Customer : ...".'];

                    continue;
                }
                if (abs($customerUnpaidSum - $colE) > self::EPSILON || abs($customerOverdueSum - $colH) > self::EPSILON) {
                    $subtotalMismatches[] = [
                        'customer_code' => $currentCode,
                        'customer_name' => $currentName,
                        'row' => $rowNo,
                        'file_unpaid' => $colE,
                        'file_overdue' => $colH,
                        'computed_unpaid' => $customerUnpaidSum,
                        'computed_overdue' => $customerOverdueSum,
                    ];
                }
                $currentCode = null; // subtotal consumed -- next real row must be a new Customer marker

                continue;
            }

            // Real transaction row.
            if ($currentCode === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => 'Baris transaksi ditemukan di luar grup "Customer : ...".'];

                continue;
            }
            if ($colB === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => 'Ref. No kosong.'];

                continue;
            }

            $txnDate = $colA !== null ? DataCleaner::normalizeDate((string) $colA) : null;
            $dueDateRaw = DataCleaner::blankToNull($row[6] ?? null);
            $dueDate = $dueDateRaw !== null ? DataCleaner::normalizeDate((string) $dueDateRaw) : null;

            if ($txnDate === null || $dueDate === null) {
                $failedRows[] = ['row' => $rowNo, 'reason' => "Tanggal tidak valid (Date: \"{$colA}\", Due Date: \"{$dueDateRaw}\")."];

                continue;
            }

            $invoiceAmount = DataCleaner::normalizeNumber($row[2] ?? null) ?? 0.0;
            $paidAmount = DataCleaner::normalizeNumber($row[3] ?? null) ?? 0.0;
            $unpaidAmount = $colE ?? 0.0;
            $termsDays = DataCleaner::normalizeNumber($row[5] ?? null);
            $overdueAmount = $colH ?? 0.0;
            $overdueDays = (int) (DataCleaner::normalizeNumber($row[8] ?? null) ?? 0);

            $customerUnpaidSum += $unpaidAmount;
            $customerOverdueSum += $overdueAmount;

            $lines[] = [
                'customer_code' => $currentCode,
                'customer_name' => $currentName,
                'txn_date' => $txnDate,
                'ref_no' => trim((string) $colB),
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
            'customer_count' => $customerCount,
            'failed_rows' => $failedRows,
            'subtotal_mismatches' => $subtotalMismatches,
            'grand_total_mismatch' => $grandTotalMismatch,
        ];
    }
}
