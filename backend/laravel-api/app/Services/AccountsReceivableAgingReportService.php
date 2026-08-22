<?php

namespace App\Services;

use App\Models\AccountsReceivable;
use App\Repositories\AccountsReceivableRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\CreditNoteRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentAllocationRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "AR Detail" export rebuild — Customer Detail Aging / Customer Summary Aging, rebuilt to match
 * the client's real legacy-system export files (xlsCustomerDetailAging.xlsx /
 * xlsCustomerSummaryAging.xlsx) byte-for-byte in structure, not just the prose ticket spec (the
 * real files were opened directly with PhpSpreadsheet and are the ground truth wherever they
 * disagree with prose — see the plan doc for the specific discrepancies found).
 *
 * Row scope is exactly AccountsReceivableRepository::searchAll()'s filtered/selected set (SI/TR
 * invoices only) — the reference files' legacy GJ/PV/OR document types and PK-/PL- customer codes
 * are a different, no-longer-existing ledger system and are deliberately not reproduced
 * (user-confirmed).
 *
 * Aging bucket assignment is empirically verified against the real 13k-row reference file (100%
 * match across every qualifying row, see the plan doc): it is the calendar-month difference
 * between a row's INVOICE date and the report's "as at" date, clamped [0,5] — NOT a days-overdue
 * threshold. This is independent of Overdue Days/Overdue Amount, which stay due-date-based (same
 * calc as AccountsReceivableService::groupedDetail(), via overdueFigures()).
 */
class AccountsReceivableAgingReportService
{
    private const BUCKET_LABELS = ['Current', '1 Month', ' 2 Month', ' 3 Month', ' 4 Month', '>4 Months'];

    private const SUMMARY_BUCKET_LABELS = ['Current', '1 Month', '2 Month', '3 Month', '4 Month', '>4 Month'];

    public function __construct(
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected CompanyRepository $companyRepository,
        protected PaymentAllocationRepository $paymentAllocationRepository,
        protected InvoiceRepository $invoiceRepository,
        protected CreditNoteRepository $creditNoteRepository,
    ) {}

    /** Same filtered/selected row set the on-screen Aging List and the old export both used — invoice_ids (a selection) already flows through filteredQuery() unchanged. */
    public function rows(array $filters): Collection
    {
        return $this->accountsReceivableRepository->searchAll($filters);
    }

    public function detailReport(Collection $rows, string $format): array
    {
        $asAt = now();
        $shaped = $rows->map(fn (AccountsReceivable $row) => $this->shapeRow($row, $asAt));

        $out = [];
        $styleRanges = [];
        $mergeRanges = [];
        $numberFormats = [];

        $this->appendTitleBlock($out, 'Customers Detail Aging - By Customer', $asAt);

        $headerRowNum = count($out) + 1;
        $out[] = ['Document No.', 'Date', ...self::BUCKET_LABELS, 'Total Outstanding', 'Due Date', 'Overdue Days', 'Overdue Amount'];
        $styleRanges[] = ['range' => "A{$headerRowNum}:L{$headerRowNum}", 'bold' => true, 'hAlign' => 'center', 'vAlign' => 'center', 'borderAll' => true];
        $styleRanges[] = ['range' => "J{$headerRowNum}:L{$headerRowNum}", 'fontName' => 'Arial', 'fontSize' => 9, 'bold' => true];
        $out[] = [''];

        $groups = $shaped->groupBy('customer_id')->sortBy(fn ($group) => $group->first()['customer_code']);

        $bucketTotals = array_fill(0, 6, 0.0);
        $grandOverdueAmount = 0.0;

        foreach ($groups as $customerRows) {
            $first = $customerRows->first();

            $headerRow = count($out) + 1;
            $out[] = [
                "Customer : {$first['customer_code']} - {$first['customer_name']}, Sales Person : ",
                '', '', '',
                'Ctn: , '.$first['phone'].', Tel : , Fax : , Terms : '.$first['term_days'].' days, Credit Limit : '.number_format($first['credit_limit'], 2).', Currency Code : RP',
            ];
            $mergeRanges[] = "A{$headerRow}:D{$headerRow}";
            $mergeRanges[] = "E{$headerRow}:L{$headerRow}";
            $styleRanges[] = ['range' => "A{$headerRow}:D{$headerRow}", 'bold' => true, 'borderTop' => true, 'borderBottom' => true, 'borderLeft' => true];
            $styleRanges[] = ['range' => "E{$headerRow}:L{$headerRow}", 'bold' => true, 'borderTop' => true, 'borderBottom' => true, 'borderRight' => true];

            $subtotal = array_fill(0, 6, 0.0);
            $subtotalOutstanding = 0.0;
            $subtotalOverdue = 0.0;

            foreach ($customerRows as $r) {
                $bucketAmounts = array_fill(0, 6, 0.0);
                $bucketAmounts[$r['bucket']] = $r['outstanding'];
                foreach ($bucketAmounts as $i => $v) {
                    $subtotal[$i] += $v;
                    $bucketTotals[$i] += $v;
                }
                $subtotalOutstanding += $r['outstanding'];
                $subtotalOverdue += $r['overdue_amount'];
                $grandOverdueAmount += $r['overdue_amount'];

                $rowNum = count($out) + 1;
                $out[] = [
                    $r['document_no'], $r['invoice_date']->format('d/m/Y'),
                    ...$bucketAmounts,
                    $r['outstanding'],
                    $r['due_date']?->format('d/m/Y'),
                    $r['overdue_days'] > 0 ? $r['overdue_days'] : '-',
                    $r['overdue_amount'],
                ];
                $numberFormats[] = ['range' => "B{$rowNum}", 'format' => 'dd/mm/yyyy'];
                if ($r['due_date']) {
                    $numberFormats[] = ['range' => "J{$rowNum}", 'format' => 'dd/mm/yyyy'];
                }
                if ($format === 'xlsx') {
                    $numberFormats[] = ['range' => "C{$rowNum}:I{$rowNum}", 'format' => '#,##0.00'];
                    $numberFormats[] = ['range' => "L{$rowNum}", 'format' => '#,##0.00'];
                }
            }

            $subtotalRow = count($out) + 1;
            $out[] = ['', '', ...$subtotal, $subtotalOutstanding, '', '', $subtotalOverdue];
            $styleRanges[] = ['range' => "C{$subtotalRow}", 'bold' => true, 'borderTop' => true, 'borderBottom' => true, 'borderLeft' => true];
            $styleRanges[] = ['range' => "D{$subtotalRow}:I{$subtotalRow}", 'bold' => true, 'hAlign' => 'right', 'borderTop' => true, 'borderBottom' => true];
            $styleRanges[] = ['range' => "L{$subtotalRow}", 'bold' => true, 'fontName' => 'Arial', 'fontSize' => 9, 'borderTop' => true, 'borderBottom' => true, 'borderRight' => true];
            if ($format === 'xlsx') {
                $numberFormats[] = ['range' => "C{$subtotalRow}:I{$subtotalRow}", 'format' => '#,##0.00'];
                $numberFormats[] = ['range' => "L{$subtotalRow}", 'format' => '#,##0.00'];
            }

            $out[] = [''];
        }

        $grandRow = count($out) + 1;
        $out[] = ['Grand Total', '', ...$bucketTotals, array_sum($bucketTotals), '', '', $grandOverdueAmount];
        $styleRanges[] = ['range' => "C{$grandRow}", 'bold' => true, 'borderTop' => true, 'borderBottom' => true, 'borderLeft' => true];
        $styleRanges[] = ['range' => "D{$grandRow}:I{$grandRow}", 'bold' => true, 'hAlign' => 'right', 'borderTop' => true, 'borderBottom' => true];
        $styleRanges[] = ['range' => "L{$grandRow}", 'bold' => true, 'fontName' => 'Arial', 'fontSize' => 9, 'borderTop' => true, 'borderBottom' => true, 'borderRight' => true];
        if ($format === 'xlsx') {
            $numberFormats[] = ['range' => "C{$grandRow}:I{$grandRow}", 'format' => '#,##0.00'];
            $numberFormats[] = ['range' => "L{$grandRow}", 'format' => '#,##0.00'];
        }

        $out[] = [''];
        $this->appendSummaryFooter($out, $styleRanges, $numberFormats, $bucketTotals, $asAt, $format);

        return [
            'rows' => $out,
            'meta' => [
                'format' => $format,
                'mergeRanges' => $mergeRanges,
                'styleRanges' => $styleRanges,
                'numberFormats' => $numberFormats,
                'columnWidths' => ['A' => 20, 'B' => 20, 'C' => 15, 'D' => 15, 'E' => 15, 'F' => 15, 'G' => 15, 'H' => 15, 'I' => 20, 'J' => 15, 'K' => 15, 'L' => 20],
            ],
        ];
    }

    public function summaryReport(Collection $rows, string $format): array
    {
        $asAt = now();
        $shaped = $rows->map(fn (AccountsReceivable $row) => $this->shapeRow($row, $asAt));
        $ledgerBalances = $this->accountsReceivableRepository->ledgerBalanceByCustomerIds($shaped->pluck('customer_id')->unique()->all());

        $out = [];
        $styleRanges = [];
        $numberFormats = [];

        $this->appendTitleBlock($out, 'Customers Summary Aging', $asAt);

        $headerRowNum = count($out) + 1;
        $out[] = ['No', 'Customer #', 'Customer Name', 'Sales Person', 'Term', ...self::BUCKET_LABELS, 'Total Outstanding', 'Overdue Amount', 'Ledger Balance', 'Credit Limit'];
        $styleRanges[] = ['range' => "A{$headerRowNum}:O{$headerRowNum}", 'bold' => true, 'hAlign' => 'center', 'vAlign' => 'center', 'borderAll' => true];

        $groups = $shaped->groupBy('customer_id')->sortBy(fn ($group) => $group->first()['customer_code']);

        $bucketTotals = array_fill(0, 6, 0.0);
        $grandOverdueAmount = 0.0;
        $grandLedgerBalance = 0.0;
        $no = 0;

        foreach ($groups as $customerRows) {
            $no++;
            $first = $customerRows->first();
            // Sales Person: from that customer's most-recent receivable — searchAll() already orders latest('due_date') first, so $first is that row.
            $salesPersonName = $first['sales_person_name'] ?? '';

            $subtotal = array_fill(0, 6, 0.0);
            $subtotalOutstanding = 0.0;
            $subtotalOverdue = 0.0;
            foreach ($customerRows as $r) {
                $subtotal[$r['bucket']] += $r['outstanding'];
                $bucketTotals[$r['bucket']] += $r['outstanding'];
                $subtotalOutstanding += $r['outstanding'];
                $subtotalOverdue += $r['overdue_amount'];
                $grandOverdueAmount += $r['overdue_amount'];
            }

            $ledgerBalance = $ledgerBalances[$first['customer_id']] ?? 0.0;
            $grandLedgerBalance += $ledgerBalance;

            $rowNum = count($out) + 1;
            $out[] = [
                $no, $first['customer_code'], $first['customer_name'], $salesPersonName, $first['term_days'],
                ...$subtotal, $subtotalOutstanding, $subtotalOverdue, $ledgerBalance, $first['credit_limit'],
            ];
            if ($format === 'xlsx') {
                $numberFormats[] = ['range' => "F{$rowNum}:O{$rowNum}", 'format' => '#,##0.00'];
            }
        }

        $out[] = [''];
        $grandRow = count($out) + 1;
        $out[] = ['Grand Total', '', '', '', '', ...$bucketTotals, array_sum($bucketTotals), $grandOverdueAmount, $grandLedgerBalance, ''];
        $styleRanges[] = ['range' => "F{$grandRow}", 'bold' => true, 'borderTop' => true, 'borderBottom' => true, 'borderLeft' => true];
        $styleRanges[] = ['range' => "G{$grandRow}:M{$grandRow}", 'bold' => true, 'hAlign' => 'right', 'borderTop' => true, 'borderBottom' => true];
        $styleRanges[] = ['range' => "N{$grandRow}", 'bold' => true, 'hAlign' => 'right', 'borderTop' => true, 'borderBottom' => true, 'borderRight' => true];
        if ($format === 'xlsx') {
            $numberFormats[] = ['range' => "F{$grandRow}:N{$grandRow}", 'format' => '#,##0.00'];
        }

        $out[] = [''];
        $this->appendSummaryFooter($out, $styleRanges, $numberFormats, $bucketTotals, $asAt, $format);

        return [
            'rows' => $out,
            'meta' => [
                'format' => $format,
                'mergeRanges' => [],
                'styleRanges' => $styleRanges,
                'numberFormats' => $numberFormats,
                'columnWidths' => ['A' => 5, 'B' => 14, 'C' => 20, 'D' => 15, 'E' => 15, 'F' => 15, 'G' => 15, 'H' => 15, 'I' => 15, 'J' => 15, 'K' => 15, 'L' => 20, 'M' => 20, 'N' => 20, 'O' => 20],
            ],
        ];
    }

    private function appendTitleBlock(array &$out, string $title, Carbon $asAt): void
    {
        $company = $this->companyRepository->defaultOrById(null);
        $out[] = [$title];
        $out[] = ["Filter By Date : as at {$asAt->format('d/m/Y')}"];
        $out[] = [$company->name ?? ''];
        $out[] = [''];
    }

    /**
     * Shared byte-for-byte by both sheets (verified identical in the real reference files):
     * "Summary" label, 6 bucket rows (label/total/percent-as-text, first 3 also carrying
     * MTD/YTD Collection/Sales/CN — company-wide, ignores every report filter/selection, same
     * posture as Ledger Balance), then "BALANCE " (trailing space, verified) = sum of the 6
     * bucket totals.
     */
    private function appendSummaryFooter(array &$out, array &$styleRanges, array &$numberFormats, array $bucketTotals, Carbon $asAt, string $format): void
    {
        $balance = array_sum($bucketTotals);

        $summaryRow = count($out) + 1;
        $out[] = ['Summary'];
        $styleRanges[] = ['range' => "A{$summaryRow}", 'borderTop' => true, 'borderLeft' => true];

        $mtdFrom = $asAt->copy()->startOfMonth();
        $ytdFrom = $asAt->copy()->startOfYear();

        $extras = [
            ['MTD COLLECTION :', $this->paymentAllocationRepository->collectionTotal($mtdFrom, $asAt), 'YTD COLLECTION :', $this->paymentAllocationRepository->collectionTotal($ytdFrom, $asAt)],
            ['MTD SALES :', $this->invoiceRepository->salesTotal($mtdFrom, $asAt), 'YTD SALES :', $this->invoiceRepository->salesTotal($ytdFrom, $asAt)],
            ['MTD CN :', $this->creditNoteRepository->creditNoteTotal($mtdFrom, $asAt), 'YTD CN :', $this->creditNoteRepository->creditNoteTotal($ytdFrom, $asAt)],
        ];

        foreach (self::SUMMARY_BUCKET_LABELS as $i => $label) {
            $percent = $balance != 0.0 ? round($bucketTotals[$i] / $balance * 100, 2) : 0.0;
            $row = ['', $label, $bucketTotals[$i], number_format($percent, 2).'%'];
            $rowNum = count($out) + 1;

            if (isset($extras[$i])) {
                array_push($row, ...$extras[$i]);
            }

            $out[] = $row;
            $styleRanges[] = ['range' => "B{$rowNum}", 'hAlign' => 'right'];
            $styleRanges[] = ['range' => "D{$rowNum}", 'hAlign' => 'right'];
            if (isset($extras[$i])) {
                $styleRanges[] = ['range' => "E{$rowNum}", 'hAlign' => 'right'];
                $styleRanges[] = ['range' => "G{$rowNum}", 'hAlign' => 'right'];
                if ($format === 'xlsx') {
                    $numberFormats[] = ['range' => "F{$rowNum}", 'format' => '#,##0.00'];
                    $numberFormats[] = ['range' => "H{$rowNum}", 'format' => '#,##0.00'];
                }
            }
            if ($format === 'xlsx') {
                $numberFormats[] = ['range' => "C{$rowNum}", 'format' => '#,##0.00'];
            }
        }

        $balanceRow = count($out) + 1;
        $out[] = ['', 'BALANCE ', $balance];
        $styleRanges[] = ['range' => "B{$balanceRow}", 'hAlign' => 'right'];
        $styleRanges[] = ['range' => "C{$balanceRow}", 'bold' => true, 'borderTop' => true];
        if ($format === 'xlsx') {
            $numberFormats[] = ['range' => "C{$balanceRow}", 'format' => '#,##0.00'];
        }
    }

    /** Calendar-month difference between invoice date and "as at", clamped [0,5] — empirically verified 100% against the real reference file (see class docblock). Independent of Overdue Days/Amount (due-date based). */
    private function bucketize(Carbon $invoiceDate, Carbon $asAt): int
    {
        $months = ($asAt->year - $invoiceDate->year) * 12 + ($asAt->month - $invoiceDate->month);

        return max(0, min(5, $months));
    }

    /** @return array<string, mixed> */
    private function shapeRow(AccountsReceivable $row, Carbon $asAt): array
    {
        $outstanding = (float) $row->amount - (float) $row->paid_amount;
        $invoiceDate = $row->invoice?->invoice_date ?? $asAt;
        $overdue = $this->accountsReceivableService->overdueFigures($row->due_date, $outstanding, $asAt);

        return [
            'customer_id' => $row->customer_id,
            'customer_code' => $row->customer?->customer_code ?? '',
            'customer_name' => $row->customer?->customer_name ?? '',
            'phone' => $row->customer?->phone ?? '',
            'credit_limit' => (float) ($row->customer?->credit_limit ?? 0),
            'term_days' => $row->customer?->termsOfPayment?->days ?? 0,
            'sales_person_name' => $row->salesOrder?->salesPerson?->name,
            'invoice_id' => $row->invoice_id,
            'document_no' => $row->invoice?->document_number,
            'invoice_date' => $invoiceDate,
            'due_date' => $row->due_date,
            'outstanding' => $outstanding,
            'bucket' => $this->bucketize($invoiceDate, $asAt),
            'overdue_days' => $overdue['overdue_days'],
            'overdue_amount' => $overdue['overdue_amount'],
        ];
    }
}
