<?php

namespace App\Services\Import;

use App\Exceptions\BusinessException;
use App\Models\CustomerOutstandingSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parses the legacy "Customer Unpaid Bills With Overdue Advice" export into an append-only
 * archive snapshot -- a standalone notebook, never joined to or reconciled against the live
 * Sales/Invoice/Customer/AR module (no FK to any of them; customer_code/name are plain
 * snapshotted strings). A re-import always creates a new snapshot, never overwrites a prior one.
 *
 * Fixed file layout (row numbers are the ticket's own spec, not detected):
 *   Row 1: report title (ignored). Row 2: company name. Row 3 col A: "Date as at : dd/mm/yyyy".
 *   Row 4: blank. Row 5: column header. Row 6+: "Customer : <code> - <name>" group markers,
 *   transaction rows, a per-customer subtotal row (cols A-D and F-G blank, E and H filled),
 *   a trailing "Grand Total" row, then "Printed By :".
 *
 * The file's own per-customer subtotals and Grand Total are a checksum, not data to store --
 * verified against the sum of parsed lines and the whole import is rejected (no partial write)
 * if they disagree, the same "protect, don't silently corrupt" convention this app's other
 * legacy-report importers use (see TrialBalanceImportService, CashBookImportService).
 */
class CustomerOutstandingArchiveImportService
{
    private const EPSILON = 0.01;

    private const EXPECTED_HEADER = ['Date', 'Ref. No', 'Invoice Amt', 'Paid Amount', 'Unpaid Amount', 'Terms (Days)', 'Due Date', 'Overdue Amount', 'Overdue (Days)'];

    public function import(string $absolutePath, string $extension, string $originalFilename, ?string $importedBy): CustomerOutstandingSnapshot
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (count($rawRows) < 6) {
            throw new BusinessException('File terlalu pendek untuk format "Customer Unpaid Bills With Overdue Advice".');
        }

        $companyName = DataCleaner::blankToNull($rawRows[1][0] ?? null);
        $asOfDate = $this->extractAsOfDate($rawRows[2][0] ?? null);
        $this->assertHeaderMatches($rawRows[4] ?? []);

        [$lines, $totalUnpaid, $totalOverdue, $customerCount] = $this->parseBody(array_slice($rawRows, 5));

        return DB::transaction(function () use ($lines, $totalUnpaid, $totalOverdue, $customerCount, $asOfDate, $companyName, $originalFilename, $importedBy) {
            $snapshot = CustomerOutstandingSnapshot::query()->create([
                'source_filename' => $originalFilename,
                'company_name' => $companyName,
                'snapshot_as_of_date' => $asOfDate,
                'total_rows' => count($lines),
                'total_customers' => $customerCount,
                'grand_total_unpaid' => $totalUnpaid,
                'grand_total_overdue' => $totalOverdue,
                'imported_by' => $importedBy,
            ]);

            $now = now();
            foreach (array_chunk($lines, 500) as $chunk) {
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

    private function extractAsOfDate(mixed $cell): string
    {
        if (! is_string($cell) || ! preg_match('/Date as at\s*:\s*(\d{2}\/\d{2}\/\d{4})/i', $cell, $m)) {
            throw new BusinessException('Tidak menemukan "Date as at : dd/mm/yyyy" di baris ke-3 file -- periksa kembali formatnya.');
        }

        return DataCleaner::normalizeDate($m[1]) ?? throw new BusinessException("Tanggal snapshot tidak valid: {$m[1]}.");
    }

    private function assertHeaderMatches(array $headerRow): void
    {
        $actual = array_map(fn ($v) => trim((string) DataCleaner::blankToNull($v)), array_slice(array_values($headerRow), 0, 9));

        if ($actual !== self::EXPECTED_HEADER) {
            throw new BusinessException('Header kolom di baris ke-5 tidak sesuai format yang diharapkan ('.implode(' | ', self::EXPECTED_HEADER).') -- periksa kembali file sumber.');
        }
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: float, 2: float, 3: int} */
    private function parseBody(array $bodyRows): array
    {
        $lines = [];
        $currentCode = null;
        $currentName = null;
        $customerUnpaidSum = 0.0;
        $customerOverdueSum = 0.0;
        $customerCount = 0;
        $grandTotalUnpaid = null;
        $grandTotalOverdue = null;

        foreach ($bodyRows as $i => $row) {
            $rowNo = $i + 6;
            $colA = DataCleaner::blankToNull($row[0] ?? null);
            $colB = DataCleaner::blankToNull($row[1] ?? null);
            $colE = DataCleaner::normalizeNumber($row[4] ?? null, 'dot_decimal');
            $colH = DataCleaner::normalizeNumber($row[7] ?? null, 'dot_decimal');

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
                if ($currentCode !== null) {
                    $this->assertSubtotalWasSeen($currentCode, $rowNo);
                }
                $currentCode = trim($m[1]);
                $currentName = trim($m[2]);
                $customerUnpaidSum = 0.0;
                $customerOverdueSum = 0.0;
                $customerCount++;

                continue;
            }

            // Subtotal row: A-D and F-G blank, E and H filled.
            if ($colA === null && $colB === null && $colE !== null && $colH !== null) {
                if ($currentCode === null) {
                    throw new BusinessException("Baris {$rowNo}: baris subtotal ditemukan sebelum ada baris \"Customer : ...\".");
                }
                if (abs($customerUnpaidSum - $colE) > self::EPSILON || abs($customerOverdueSum - $colH) > self::EPSILON) {
                    throw new BusinessException(
                        "Subtotal customer \"{$currentName}\" tidak cocok (baris {$rowNo}): file menyatakan Unpaid ".number_format($colE, 2).
                        ', Overdue '.number_format($colH, 2).' -- hasil parsing baris transaksi adalah Unpaid '.number_format($customerUnpaidSum, 2).
                        ', Overdue '.number_format($customerOverdueSum, 2).'. Import dibatalkan, periksa kembali file-nya.'
                    );
                }
                $currentCode = null; // subtotal consumed -- next real row must be a new Customer marker

                continue;
            }

            // Real transaction row.
            if ($currentCode === null) {
                throw new BusinessException("Baris {$rowNo}: baris transaksi ditemukan di luar grup \"Customer : ...\".");
            }
            if ($colB === null) {
                throw new BusinessException("Baris {$rowNo}: Ref. No kosong pada baris transaksi.");
            }

            $txnDate = DataCleaner::normalizeDate((string) $colA);
            $dueDate = DataCleaner::normalizeDate((string) DataCleaner::blankToNull($row[6] ?? null));
            $invoiceAmount = DataCleaner::normalizeNumber($row[2] ?? null, 'dot_decimal') ?? 0.0;
            $paidAmount = DataCleaner::normalizeNumber($row[3] ?? null, 'dot_decimal') ?? 0.0;
            $unpaidAmount = $colE ?? 0.0;
            $termsDays = DataCleaner::normalizeNumber($row[5] ?? null, 'dot_decimal');
            $overdueAmount = $colH ?? 0.0;
            $overdueDays = (int) (DataCleaner::normalizeNumber($row[8] ?? null, 'dot_decimal') ?? 0);

            if ($txnDate === null || $dueDate === null) {
                throw new BusinessException("Baris {$rowNo}: tanggal tidak valid (Date: {$colA}, Due Date: ".($row[6] ?? '').').');
            }

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

        $sumUnpaid = array_sum(array_column($lines, 'unpaid_amount'));
        $sumOverdue = array_sum(array_column($lines, 'overdue_amount'));

        if (abs($sumUnpaid - $grandTotalUnpaid) > self::EPSILON || abs($sumOverdue - $grandTotalOverdue) > self::EPSILON) {
            throw new BusinessException(
                'Grand Total tidak cocok: file menyatakan Unpaid '.number_format($grandTotalUnpaid, 2).', Overdue '.number_format($grandTotalOverdue, 2).
                ' -- hasil parsing adalah Unpaid '.number_format($sumUnpaid, 2).', Overdue '.number_format($sumOverdue, 2).
                '. Import dibatalkan, periksa kembali file-nya.'
            );
        }

        return [$lines, $sumUnpaid, $sumOverdue, $customerCount];
    }

    private function assertSubtotalWasSeen(string $previousCode, int $rowNo): void
    {
        // A new "Customer :" marker appearing while $currentCode is still set (not nulled by a
        // subtotal row) means the previous customer's subtotal row was missing entirely.
        throw new BusinessException("Baris subtotal untuk customer \"{$previousCode}\" tidak ditemukan sebelum baris {$rowNo} (customer berikutnya) -- periksa kembali file-nya.");
    }
}
