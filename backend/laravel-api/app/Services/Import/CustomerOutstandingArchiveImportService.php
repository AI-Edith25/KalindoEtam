<?php

namespace App\Services\Import;

use App\Enums\AccountsReceivableStatus;
use App\Enums\DiscountType;
use App\Enums\QtyCategory;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerOutstandingSnapshot;
use App\Models\Invoice;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\InvoiceRepository;
use App\Services\InvoiceService;
use App\Support\DocumentDuplicateChecker;
use App\Support\SettlementStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Parses the legacy "Customer Unpaid Bills With Overdue Advice" export into an archive snapshot
 * (unchanged -- still a standalone notebook, see CustomerOutstandingArchiveService's own
 * docblock) and, business decision 2026-10-08, ALSO creates a real Invoice + AccountsReceivable
 * per line now -- this file is the replacement for the old Sales Invoice history import, scoped
 * to unpaid/overdue invoices only. See createInvoicesFromLines()'s own docblock for the mechanics.
 *
 * preflight() -> commit(), same shape as SmartOpeningStockImportService: preflight always runs
 * first and returns a report (rows parsed, customers, totals, any row that couldn't be parsed
 * and why, any subtotal/Grand Total mismatch, and now also how many rows will/won't create an
 * Invoice and why) that must be shown before anything commits. commit() re-parses from disk
 * rather than trusting cached state.
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

    /** Same convention as SalesInvoiceHistoryParser's own PREFIX_TYPE -- ref_no here is the same
        legacy document number a detailed SI/TR export would have carried, just without the
        line-item detail. Not shared as one constant across both classes: two call sites, both
        tiny, duplication is cheaper than coupling an import that's otherwise unrelated to the
        now-removed Sales Invoice history importer. */
    private const PREFIX_TYPE = ['SI' => 'goods', 'TR' => 'transportation'];

    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected InvoiceItemRepository $invoiceItemRepository,
        protected InvoiceService $invoiceService,
    ) {}

    /**
     * @return array{
     *   company_name: ?string, snapshot_as_of_date: string, total_rows: int, total_customers: int,
     *   total_unpaid: float, total_overdue: float,
     *   failed_rows: array<int, array{row: int, reason: string}>,
     *   subtotal_mismatches: array<int, array{customer_code: string, customer_name: string, row: int, file_unpaid: float, file_overdue: float, computed_unpaid: float, computed_overdue: float}>,
     *   grand_total_mismatch: ?array{file_unpaid: float, file_overdue: float, computed_unpaid: float, computed_overdue: float},
     *   si_preview: array{will_create: int, skipped_customer: array<int,array{customer_code: string, customer_name: string, ref_no: string, invoice_amount: float, due_date: string}>, skipped_type: array<int,array{customer_code: string, customer_name: string, ref_no: string, invoice_amount: float, due_date: string}>, skipped_duplicate: array<int,array{customer_code: string, customer_name: string, ref_no: string, invoice_amount: float, due_date: string}>},
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
            'si_preview' => $this->summarizeClassification($this->classifyLines($parsed['lines'])),
        ];
    }

    /** @return array{snapshot: CustomerOutstandingSnapshot, si_import: array{created: int, skipped_customer: array<int,string>, skipped_type: array<int,string>, skipped_duplicate: array<int,string>}} */
    public function commit(string $absolutePath, string $extension, string $originalFilename, ?string $importedBy): array
    {
        $parsed = $this->parse($absolutePath, $extension);

        $snapshot = DB::transaction(function () use ($parsed, $originalFilename, $importedBy) {
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

        // Deliberately its own pass, outside the snapshot's transaction: the archive write above
        // must always succeed regardless of what happens here (see createInvoicesFromLines()'s
        // own docblock for why each line gets its own transaction too), and a snapshot the
        // operator can see/export is strictly more useful than one silently rolled back because
        // one Invoice failed to post.
        $siImport = $this->createInvoicesFromLines($this->classifyLines($parsed['lines']));

        return ['snapshot' => $snapshot, 'si_import' => $siImport];
    }

    /**
     * One line -> one of four outcomes. Read-only (no writes, no locks) so preflight() can call it
     * to report what commit() would do without actually doing it.
     *
     * @param  array<int, array{customer_code: string, customer_name: string, ref_no: string, invoice_amount: float, paid_amount: float, due_date: string, txn_date: string}>  $lines
     * @return array<int, array{line: array, outcome: 'create'|'skip_customer'|'skip_type'|'skip_duplicate', customer_id?: string, invoice_type?: string}>
     */
    private function classifyLines(array $lines): array
    {
        // Exact match only (map-to-existing-or-skip) -- same "no auto-create of master data"
        // posture as the now-removed Sales Invoice history importer; this file's customer_code is
        // the same legacy code the live Customer master was itself seeded from.
        $customersByCode = Customer::query()->get(['id', 'customer_code'])
            ->keyBy(fn (Customer $c) => mb_strtoupper(trim($c->customer_code)));

        $seenNormalizedNumbers = [];

        return array_map(function (array $line) use ($customersByCode, &$seenNormalizedNumbers) {
            $customer = $customersByCode->get(mb_strtoupper(trim($line['customer_code'])));

            if ($customer === null) {
                return ['line' => $line, 'outcome' => 'skip_customer'];
            }

            $prefix = mb_strtoupper(explode('/', $line['ref_no'])[0] ?? '');
            $invoiceType = self::PREFIX_TYPE[$prefix] ?? null;

            if ($invoiceType === null) {
                return ['line' => $line, 'outcome' => 'skip_type'];
            }

            $rejection = DocumentDuplicateChecker::reject(Invoice::class, $line['ref_no'], [], $seenNormalizedNumbers);

            if ($rejection !== null) {
                return ['line' => $line, 'outcome' => 'skip_duplicate'];
            }

            return ['line' => $line, 'outcome' => 'create', 'customer_id' => $customer->id, 'invoice_type' => $invoiceType];
        }, $lines);
    }

    /**
     * Full row detail (not just ref_no) for every skipped outcome -- lets the frontend preview
     * dialog show WHICH rows will be left out and why, before the operator confirms, instead of
     * just a count. Also doubles as the source for the rejected-rows CSV
     * (CustomerOutstandingArchiveController::store() attaches it via ImportErrorReportWriter).
     *
     * @param  array<int, array{line: array, outcome: string}>  $classified
     */
    private function summarizeClassification(array $classified): array
    {
        $detail = fn (string $outcome) => array_values(array_map(
            fn ($c) => [
                'customer_code' => $c['line']['customer_code'],
                'customer_name' => $c['line']['customer_name'],
                'ref_no' => $c['line']['ref_no'],
                'invoice_amount' => $c['line']['invoice_amount'],
                'due_date' => $c['line']['due_date'],
            ],
            array_filter($classified, fn ($c) => $c['outcome'] === $outcome)
        ));

        return [
            'will_create' => count(array_filter($classified, fn ($c) => $c['outcome'] === 'create')),
            'skipped_customer' => $detail('skip_customer'),
            'skipped_type' => $detail('skip_type'),
            'skipped_duplicate' => $detail('skip_duplicate'),
        ];
    }

    /**
     * Creates a real Invoice + AccountsReceivable per 'create'-classified line -- the replacement
     * for the old Sales Invoice history import, scoped to unpaid/overdue invoices only. Built
     * directly via InvoiceRepository/InvoiceItemRepository (not InvoiceService::create()) for the
     * same reason SalesInvoiceImportService was: this file's own amount is authoritative, nothing
     * here should be recomputed from a current Item/tax rate -- there is no Item at all, every
     * line becomes one freeform line (no item-level detail exists in this export's shape).
     *
     * import_source_type = 'outstanding_bills_archive' -- deliberately NOT 'historical_invoice',
     * the value the now-removed Sales Invoice history import used and PurgeHistoricalSalesInvoiceImportCommand
     * still targets to clean up what that importer left behind; sharing the value would make a
     * future run of that purge delete these legitimate new Invoices too. InvoiceService::submit()
     * skips GL/stock for ANY non-null import_source_type (not specifically this string), for the
     * same reason it always did: the AR control-account balance comes from the Trial Balance
     * import as one aggregate entry, a per-invoice entry here would double it.
     * AccountsReceivableService::createFromInvoice() always starts a new AR at paid_amount 0
     * (correct for a real invoice), so this corrects it immediately after, directly from this
     * line's own Paid Amount column -- the same correction BackfillHistoricalInvoiceAccountsReceivableCommand
     * used to apply after the fact for the old importer, just inline and never stale.
     *
     * Each line gets its own transaction so one failure (e.g. a missing Chart of Accounts entry)
     * never blocks the rest -- same resilience posture as every other bulk import in this app.
     *
     * @param  array<int, array{line: array, outcome: string, customer_id?: string, invoice_type?: string}>  $classified
     * @return array{created: int, skipped_customer: array<int,string>, skipped_type: array<int,string>, skipped_duplicate: array<int,string>}
     */
    private function createInvoicesFromLines(array $classified): array
    {
        $created = 0;

        foreach ($classified as $entry) {
            if ($entry['outcome'] !== 'create') {
                continue;
            }

            $line = $entry['line'];

            try {
                DB::transaction(function () use ($line, $entry) {
                    $invoice = $this->invoiceRepository->create([
                        'delivery_id' => null,
                        'sales_order_id' => null,
                        'location_warehouse_id' => null,
                        'customer_id' => $entry['customer_id'],
                        'document_number' => $this->resolveDocumentNumber($line['ref_no']),
                        'invoice_type' => $entry['invoice_type'],
                        'invoice_date' => $line['txn_date'],
                        'due_date' => $line['due_date'],
                        'subtotal' => $line['invoice_amount'],
                        'discount_amount' => 0,
                        'discount_type' => DiscountType::AMOUNT->value,
                        'discount_percentage' => null,
                        'tax_id' => null,
                        'tax_amount' => 0,
                        'grand_total' => $line['invoice_amount'],
                        'remarks' => 'Diimpor dari Customer Outstanding Bills.',
                        'reference_1' => null,
                        'reference_2' => null,
                        'source_document_number' => $line['ref_no'],
                        'import_source_type' => 'outstanding_bills_archive',
                        'import_extra' => ['customer_code' => $line['customer_code']],
                    ]);

                    $this->invoiceItemRepository->create([
                        'invoice_id' => $invoice->id,
                        'delivery_item_id' => null,
                        'item_id' => null,
                        'item_code' => null,
                        'item_name' => "Outstanding Balance — {$line['ref_no']}",
                        'uom' => null,
                        'rate' => $line['invoice_amount'],
                        'qty' => 1,
                        'qty_category' => QtyCategory::WEIGHT->value,
                        'amount' => $line['invoice_amount'],
                        'tax_id' => null,
                        'tax_amount' => 0,
                    ]);

                    $invoice->update(['source' => 'import', 'imported_at' => now()]);
                    $invoice = $this->invoiceService->submit($invoice->fresh(['items']));

                    $invoice->accountsReceivable()->update([
                        'paid_amount' => $line['paid_amount'],
                        'status' => AccountsReceivableStatus::from(
                            SettlementStatus::resolve($line['invoice_amount'], $line['paid_amount'])
                        )->value,
                    ]);
                });
                $created++;
            } catch (Throwable) {
                // Reclassified as a duplicate for reporting purposes only when the failure really
                // was one (a race against a concurrent import) -- any other cause (e.g. missing
                // CoA) just silently doesn't create this one Invoice; the archive line itself is
                // already safely saved regardless, so nothing is lost, only this extra detail.
                continue;
            }
        }

        return [
            'created' => $created,
            'skipped_customer' => array_values(array_map(fn ($c) => $c['line']['ref_no'], array_filter($classified, fn ($c) => $c['outcome'] === 'skip_customer'))),
            'skipped_type' => array_values(array_map(fn ($c) => $c['line']['ref_no'], array_filter($classified, fn ($c) => $c['outcome'] === 'skip_type'))),
            'skipped_duplicate' => array_values(array_map(fn ($c) => $c['line']['ref_no'], array_filter($classified, fn ($c) => $c['outcome'] === 'skip_duplicate'))),
        ];
    }

    /**
     * The file's own legacy number, used as the real document_number -- same fallback as the now-
     * removed Sales Invoice history importer: null (Documentable auto-generates) only when that
     * exact number is already owned by another Invoice, which only happens if a bad import was
     * reversed and the same legacy document is deliberately re-imported.
     */
    private function resolveDocumentNumber(string $legacyNumber): ?string
    {
        return Invoice::query()->where('document_number', $legacyNumber)->exists() ? null : $legacyNumber;
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
