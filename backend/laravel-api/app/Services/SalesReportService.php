<?php

namespace App\Services;

use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Repositories\CompanyRepository;
use App\Repositories\InvoiceRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Sales → Invoices' "Laporan Penjualan" export — Summary (one row per invoice) and Detail (one
 * header row per invoice plus a sub-row per line item), both built from the same filtered/selected
 * Invoice set. Row-shaping only; SalesReportController picks the Export class (XLSX/CSV writer)
 * and file name. Column layout, header/footer block, and the Tax Summary section are all modeled
 * directly on the client's real legacy-system export samples (xlsSalesInvoiceListing_Detail.xlsx /
 * _Summary.xlsx), not just the ticket's prose spec — the reference files are the ground truth for
 * exact column positions/labels/spacing.
 */
class SalesReportService
{
    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected CompanyRepository $companyRepository,
    ) {}

    /** @param array<string, mixed> $filters */
    public function rows(array $filters, ?array $ids = null): Collection
    {
        return $this->invoiceRepository->searchAll($filters, $ids);
    }

    /**
     * DATE, DOCUMENT, CUSTOMER, CUSTOMER NAME, EXCL.TAX, DISC, TAX, INCL.TAX, REFERENCE 1,
     * REFERENCE 2, ADD BY, UPDATE BY, plus a trailing "Total By Header" row (label one column
     * before EXCL.TAX). DATE is a real Excel date value (see excelDate()) so it sorts/filters as a
     * date in XLSX — StylesSalesReportSheet applies the dd/mm/yyyy display format. The four money
     * columns are pre-formatted Indonesian-style text ("5.000,00" — period thousands, comma
     * decimal), not raw numeric: PhpSpreadsheet's format-code engine always treats "," as the
     * thousands token and "." as the decimal token regardless of which literal character is used
     * in a custom format string, so there is no numeric-cell format code that renders this
     * correctly independent of the opening application's locale — confirmed empirically, not
     * assumed. Writing the literal text is the only way to guarantee "5.000,00" everywhere.
     * formatMoney(0) already yields "0,00", so a genuinely-zero DISC/TAX never renders blank.
     *
     * @return array<int, array<int, mixed>>
     */
    public function summaryRows(Collection $invoices): array
    {
        $rows = $invoices->map(fn (Invoice $invoice) => [
            $this->excelDate($invoice->invoice_date),
            $invoice->document_number,
            $invoice->customer?->customer_code,
            $invoice->customer?->customer_name,
            $this->formatMoney((float) $invoice->subtotal),
            $this->formatMoney((float) $invoice->discount_amount),
            $this->formatMoney((float) $invoice->tax_amount),
            $this->formatMoney((float) $invoice->grand_total),
            $invoice->reference_1,
            $invoice->reference_2,
            $invoice->creator?->name,
            $invoice->updater?->name,
        ])->all();

        $rows[] = [
            null, null, null, 'Total By Header',
            $this->formatMoney($invoices->sum(fn (Invoice $i) => (float) $i->subtotal)),
            $this->formatMoney($invoices->sum(fn (Invoice $i) => (float) $i->discount_amount)),
            $this->formatMoney($invoices->sum(fn (Invoice $i) => (float) $i->tax_amount)),
            $this->formatMoney($invoices->sum(fn (Invoice $i) => (float) $i->grand_total)),
            null, null, null, null,
        ];

        return $rows;
    }

    /**
     * Flat table, one row per line item — no more stacking a document-level row and an item-level
     * row onto the same physical columns. Document-level values (DATE..REFERENCE 2) repeat on
     * every item row of that invoice, so each row stands alone for filtering/sorting. Column order:
     *   DATE(A) DOCUMENT(B) CUSTOMER(C) NAME(D) DELIVERY TO(E) DISC-DOC(F) TAX-DOC(G) T.CODE-DOC(H)
     *   AMOUNT(I) REFERENCE 1(J) REFERENCE 2(K) ITEM(L) DESCRIPTION(M) UOM(N) QUANTITY(O)
     *   UNIT PRICE(P) DISC-LINE(Q) TAX-LINE(R) T.CODE-LINE(S) LINE AMOUNT(T)
     * DATE is a real Excel date serial (see excelDate()) so it still sorts/filters as a date.
     * UNIT PRICE/DISC/TAX/AMOUNT/LINE AMOUNT are pre-formatted Indonesian-style text ("5.000,00"),
     * same rule and same reason as summaryRows() — formatMoney(0) already yields "0,00", so a
     * genuinely-zero DISC/TAX never renders blank.
     *
     * @return array<int, array<int, mixed>>
     */
    public function detailRows(Collection $invoices): array
    {
        $rows = [];

        foreach ($invoices as $invoice) {
            /** @var Invoice $invoice */
            $header = [
                $this->excelDate($invoice->invoice_date),
                $invoice->document_number,
                $invoice->customer?->customer_code,
                $invoice->customer?->customer_name,
                $invoice->delivery?->warehouse?->name,
                $this->formatMoney((float) $invoice->discount_amount),
                $this->formatMoney((float) $invoice->tax_amount),
                $this->headerTaxCode($invoice),
                $this->formatMoney((float) $invoice->grand_total),
                $invoice->reference_1,
                $invoice->reference_2,
            ];

            foreach ($invoice->items as $item) {
                $rows[] = [
                    ...$header,
                    $item->item_code,
                    $item->item_name,
                    $item->uom,
                    (int) $item->qty,
                    $this->formatMoney((float) $item->rate),
                    // No per-line discount exists anywhere in this system (discount is
                    // document-level only, already in the header columns above) — always 0
                    // here rather than a pro-rated split, which would invent numbers that
                    // don't reconcile to anything actually stored.
                    $this->formatMoney(0.0),
                    $this->formatMoney((float) $item->tax_amount),
                    $item->tax?->code,
                    $this->formatMoney((float) $item->amount),
                ];
            }
        }

        return $rows;
    }

    /**
     * A Goods invoice's tax is per-line since the tax refactor — no single header code exists
     * once lines disagree. Same "blank when mixed, the real code when uniform" rule already
     * shipped for DeliveryResource's own header tax_id.
     */
    protected function headerTaxCode(Invoice $invoice): ?string
    {
        $codes = $invoice->items->pluck('tax.code')->filter()->unique();

        return $codes->count() === 1 ? $codes->first() : null;
    }

    /**
     * No column header in this report ever carries a trailing "#" — a standing convention in this
     * codebase (previously enforced on the Invoice checkbox print flow's own column headers).
     *
     * @return array<int, mixed>
     */
    public function summaryHeadings(): array
    {
        return ['DATE', 'DOCUMENT', 'CUSTOMER', 'CUSTOMER NAME', 'EXCL.TAX', 'DISC', 'TAX', 'INCL.TAX', 'REFERENCE 1', 'REFERENCE 2', 'ADD BY', 'UPDATE BY'];
    }

    /** @return array<int, mixed> single flat heading row matching detailRows()' column order */
    public function detailHeadings(): array
    {
        return [
            'DATE', 'DOCUMENT', 'CUSTOMER', 'NAME', 'DELIVERY TO', 'DISC (DOC)', 'TAX (DOC)', 'T.CODE (DOC)', 'AMOUNT', 'REFERENCE 1', 'REFERENCE 2',
            'ITEM', 'DESCRIPTION', 'UOM', 'QUANTITY', 'UNIT PRICE', 'DISC (LINE)', 'TAX (LINE)', 'T.CODE (LINE)', 'LINE AMOUNT',
        ];
    }

    /** "5.000,00" — period thousands, comma decimal, always 2dp, regardless of the opening application's locale. See summaryRows()'s own docblock for why this must be text, not a numeric format code. */
    protected function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    /**
     * A real Excel date serial (not a string) so Summary's DATE column sorts/filters as a date in
     * XLSX — PhpSpreadsheet's own value binder does NOT auto-detect \DateTimeInterface values
     * (confirmed empirically), so the conversion must happen explicitly here; the dd/mm/yyyy
     * display format is applied separately, over this column's cell range, by
     * StylesSalesReportSheet. Returns '' for a null date — harmless on both an unset numeric
     * format and an empty CSV field.
     */
    protected function excelDate(?Carbon $date): float|string
    {
        return $date ? ExcelDate::PHPToExcel($date) : '';
    }

    /**
     * Wraps a mode's already-shaped $bodyRows in the report's full header/footer block — title,
     * date range, company/timestamp, column heading row(s), the body (Summary's own $bodyRows
     * already ends with its trailing "Total By Header" row — see summaryRows()), the Tax Summary
     * section, and "Printed By". The one shared path both Detail and Summary export files build
     * their full row set from, so CSV/XLSX for a given mode can never diverge in content (only in
     * how StylesSalesReportSheet renders the rows).
     *
     * Returns ['rows' => ..., 'boldRows' => ..., ...] rather than a plain row array — the Tax
     * Summary section's position depends on how many body rows precede it, so which rows need
     * bold styling (the heading row(s), "TAX SUMMARY", and its own "Code/Rate/..." header), and
     * which rows are the actual data table (for $dateColumn's number format / $withDataBorders),
     * can only be known after the whole array is assembled, not as fixed row numbers.
     *
     * @param array<int, array<int, mixed>> $headingRows one or more heading rows (Summary: 1, Detail: 1)
     * @param array<int, array<int, mixed>> $bodyRows
     * @param array<string, mixed> $filters
     * @param string $lastColumn rightmost column letter of this mode's table — bold/border/alignment ranges span A:$lastColumn
     * @param string|null $dateColumn column letter to apply $dateFormat's display format to, over the body row range; null = untouched
     * @param bool $withDataBorders thin borders around every cell from the heading row(s) through the last body row
     * @param string $dateFormat number-format code applied to $dateColumn (ignored if $dateColumn is null)
     * @param array<int, string> $rightAlignColumns column letters to right-align over the heading+body range; every other column in A:$lastColumn is explicitly left-aligned for the same range so header and data never disagree. Empty = leave alignment untouched.
     * @return array{rows: array<int, array<int, mixed>>, boldRows: array<int, int>, lastColumn: string, dateColumn: ?string, dateFormat: string, dateRowRange: ?array{0: int, 1: int}, borderRange: ?array{0: int, 1: int}, rightAlignColumns: array<int, string>, alignRange: ?array{0: int, 1: int}}
     */
    public function wrapReport(
        string $title,
        array $headingRows,
        array $bodyRows,
        array $filters,
        Collection $invoices,
        string $lastColumn = 'M',
        ?string $dateColumn = null,
        bool $withDataBorders = false,
        string $dateFormat = 'dd/mm/yyyy',
        array $rightAlignColumns = [],
    ): array {
        $company = $this->companyRepository->defaultOrById(null);

        $rows = [
            [$title],
            [$this->dateRangeLabel($filters, $invoices) . ' - Base Currency'],
            [''],
            [''],
            [$company->name ?? '', '', '', now()->format('d/m/Y H:i:s')],
            [''],
            [''],
        ];

        $boldRows = [];
        $firstHeadingRow = count($rows) + 1;
        foreach ($headingRows as $headingRow) {
            $rows[] = $headingRow;
            $boldRows[] = count($rows);
        }

        $firstBodyRow = count($rows) + 1;
        array_push($rows, ...$bodyRows);
        $lastBodyRow = count($rows);
        $tableRange = [$firstHeadingRow, $lastBodyRow];

        $rows[] = [''];
        $rows[] = [''];
        $rows[] = ['TAX SUMMARY'];
        $boldRows[] = count($rows);
        $rows[] = ['Code', 'Rate', 'Goods Amount', 'Tax Amount'];
        $boldRows[] = count($rows);
        array_push($rows, ...$this->taxSummaryRows($invoices));

        $rows[] = [''];
        $rows[] = ['Printed By :', Auth::user()?->name];

        return [
            'rows' => $rows,
            'boldRows' => $boldRows,
            'lastColumn' => $lastColumn,
            'dateColumn' => $dateColumn,
            'dateFormat' => $dateFormat,
            'dateRowRange' => $dateColumn !== null ? [$firstBodyRow, $lastBodyRow] : null,
            'borderRange' => $withDataBorders ? $tableRange : null,
            'rightAlignColumns' => $rightAlignColumns,
            'alignRange' => $rightAlignColumns !== [] ? $tableRange : null,
        ];
    }

    /**
     * Groups every exported row's tax by T.CODE, plus a trailing grand-total row — item-level tax
     * for Goods invoices (each line carries its own tax), header-level tax for Transportation
     * invoices (their items carry no tax at all, see InvoiceService::createTransportation()).
     * Untaxed lines/invoices group under the reference export's own literal 'NON-PPN' label at 0%.
     * Used identically by both Summary and Detail, so their Tax Summary totals always agree with
     * each other (the reference samples' own Summary/Detail totals differ slightly from one
     * another — a legacy-system quirk this shared implementation deliberately doesn't reproduce).
     *
     * @return array<int, array<int, mixed>>
     */
    protected function taxSummaryRows(Collection $invoices): array
    {
        $groups = [];

        $add = function (?string $code, float $rate, float $taxable, float $tax) use (&$groups) {
            $code ??= 'NON-PPN';
            $groups[$code] ??= ['rate' => $rate, 'taxable' => 0.0, 'tax' => 0.0];
            $groups[$code]['taxable'] += $taxable;
            $groups[$code]['tax'] += $tax;
        };

        foreach ($invoices as $invoice) {
            /** @var Invoice $invoice */
            if ($invoice->invoice_type === InvoiceType::TRANSPORTATION) {
                $add($invoice->tax?->code, (float) ($invoice->tax?->rate ?? 0), (float) $invoice->subtotal, (float) $invoice->tax_amount);

                continue;
            }

            foreach ($invoice->items as $item) {
                $add($item->tax?->code, (float) ($item->tax?->rate ?? 0), (float) $item->amount, (float) $item->tax_amount);
            }
        }

        $rows = [];
        $totalTaxable = 0.0;
        $totalTax = 0.0;

        foreach ($groups as $code => $group) {
            $rows[] = [$code, $this->formatRate($group['rate']), round($group['taxable'], 2), round($group['tax'], 2)];
            $totalTaxable += $group['taxable'];
            $totalTax += $group['tax'];
        }

        $rows[] = ['', '', round($totalTaxable, 2), round($totalTax, 2)];

        return $rows;
    }

    /** '11' -> '11 %', '0' -> '0 %' — matches the reference export's own integer-looking rate labels. */
    protected function formatRate(float $rate): string
    {
        $trimmed = rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');

        return ($trimmed === '' ? '0' : $trimmed) . ' %';
    }

    /**
     * Explicit date_from/date_to filter when both are set; otherwise derived from the min/max
     * invoice_date actually present in the exported set — the "ids" (checked-rows) export path
     * carries no date filter at all, and an unbounded filter shouldn't just print a blank range.
     */
    protected function dateRangeLabel(array $filters, Collection $invoices): string
    {
        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            return Carbon::parse($filters['date_from'])->format('d/m/Y') . ' - ' . Carbon::parse($filters['date_to'])->format('d/m/Y');
        }

        $dates = $invoices->pluck('invoice_date')->filter();
        $from = $dates->min();
        $to = $dates->max();

        return ($from ? $from->format('d/m/Y') : '') . ' - ' . ($to ? $to->format('d/m/Y') : '');
    }
}
