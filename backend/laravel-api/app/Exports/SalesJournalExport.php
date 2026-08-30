<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Sales Journal's export — Sales Invoice / Credit Note (Sales Return) sub-tabs, matching the
 * legacy JournalList_Sales.xlsx / JournalList_SalesReturn.xlsx template exactly (verified with
 * openpyxl against the real files). Unlike JournalListExport (which walks journal_entries), this
 * walks the SOURCE documents (Invoice+InvoiceItem / CreditNote+CreditNoteItem) directly: this
 * system's Accounting Engine posts only aggregate journal lines (see Invoice::journalLines()/
 * CreditNote::journalLines()) — never one line per item — so the per-item breakdown the template
 * needs simply does not exist in journal_entries. Account codes/names come from THIS system's own
 * chart_of_accounts (1200/4000/4050/2100/4900), not the legacy numbering the sample files show —
 * a different, older system; the format/structure matches, the account numbers don't need to.
 *
 * FromQuery + WithChunkReading (not FromCollection) — the real Sales export is 172k+ physical
 * rows once exploded per item (confirmed against JournalList_Sales.xlsx), same memory reasoning
 * as JournalListExport/CashBookRepository.
 */
class SalesJournalExport implements FromQuery, WithChunkReading, WithMapping, WithEvents
{
    protected const ACCOUNTS = ['1200', '4000', '4050', '2100', '4900'];

    protected float $totalDebit = 0.0;

    protected float $totalCredit = 0.0;

    protected int $rowCount = 0;

    /** @var array<string, string> chart_of_accounts code => name, resolved once, not per row. */
    protected array $accountNames;

    public function __construct(
        protected Builder $query,
        protected string $view,
        protected string $groupLabel,
        protected ?string $dateFrom = null,
        protected ?string $dateTo = null,
    ) {
        $this->accountNames = ChartOfAccount::whereIn('code', self::ACCOUNTS)->pluck('name', 'code')->all();
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    /** @return array<int, array> One physical spreadsheet row per journal line — the header row (index 0) carries the document number, every other row leaves it null. */
    public function map($document): array
    {
        return $this->view === 'credit_note' ? $this->mapCreditNote($document) : $this->mapInvoice($document);
    }

    /**
     * Header: AR debit for grand_total. Goods invoices (items present): one Sales Revenue credit
     * row per InvoiceItem, each with its own per-item Tax Payable row when item.tax_amount > 0
     * (invoice_items.tax_id/tax_amount is populated for Goods — copied from DeliveryItem at
     * creation). Transportation invoices (no items — header-tax-only, confirmed via the
     * add_tax_to_invoice_items_table migration's own docblock): a single Sales Revenue row for the
     * whole subtotal plus a single header-level Tax row, since no per-item data exists to split.
     */
    protected function mapInvoice(Invoice $invoice): array
    {
        $date = $invoice->invoice_date?->format('d/m/Y');
        $ref1 = $invoice->reference_1;
        $branchCode = ($invoice->branch ?? $invoice->salesOrder?->branch)?->code;
        $salesmanCode = $invoice->salesPerson?->code;
        $customerName = $invoice->customer->customer_name;

        $lines = [];
        $lines[] = ['particulars' => $this->particulars('1200', "Sales, {$customerName}"), 'debit' => (float) $invoice->grand_total, 'credit' => 0.0, 'taxCode' => null];

        if ($invoice->items->isEmpty()) {
            if ((float) $invoice->subtotal > 0) {
                $lines[] = ['particulars' => $this->particulars('4000', $invoice->remarks ?: "Sales, {$customerName}"), 'debit' => 0.0, 'credit' => (float) $invoice->subtotal, 'taxCode' => $invoice->tax?->code];
            }
            if ((float) $invoice->tax_amount > 0) {
                $lines[] = ['particulars' => $this->particulars('2100', 'Tax'), 'debit' => 0.0, 'credit' => (float) $invoice->tax_amount, 'taxCode' => $invoice->tax?->code];
            }
        } else {
            foreach ($invoice->items as $item) {
                $lines[] = ['particulars' => $this->particulars('4000', $item->item_name), 'debit' => 0.0, 'credit' => (float) $item->amount, 'taxCode' => $item->tax?->code];

                if ((float) $item->tax_amount > 0) {
                    $lines[] = ['particulars' => $this->particulars('2100', "Tax : {$item->item_name}"), 'debit' => 0.0, 'credit' => (float) $item->tax_amount, 'taxCode' => $item->tax?->code];
                }
            }
        }

        if ((float) $invoice->discount_amount > 0) {
            $lines[] = ['particulars' => $this->particulars('4900', 'Discount'), 'debit' => (float) $invoice->discount_amount, 'credit' => 0.0, 'taxCode' => null];
        }

        return $this->toRows($invoice->document_number, $date, $ref1, $lines, $salesmanCode, $branchCode);
    }

    /**
     * Mirrors mapInvoice() with every debit/credit swapped, contra accounts (4050/2100 debit-
     * side) — same shape as CreditNote::journalLines(). No per-item tax (credit_note_items has no
     * tax_id/tax_amount column at all) — single header-level Tax row instead. Ref.1# and Salesman/
     * Branch Code are inherited from the parent Invoice (CreditNote carries none of its own).
     */
    protected function mapCreditNote(CreditNote $creditNote): array
    {
        $invoice = $creditNote->invoice;
        $date = $creditNote->credit_note_date?->format('d/m/Y');
        $ref1 = $invoice?->reference_1;
        $branchCode = $invoice ? ($invoice->branch ?? $invoice->salesOrder?->branch)?->code : null;
        $salesmanCode = $invoice?->salesPerson?->code;
        $customerName = $creditNote->customer->customer_name;

        $lines = [];
        $lines[] = ['particulars' => $this->particulars('1200', "Credit Note To Customer, {$customerName}"), 'debit' => 0.0, 'credit' => (float) $creditNote->total_amount, 'taxCode' => null];

        if ((float) $creditNote->subtotal > 0) {
            if ($creditNote->items->isEmpty()) {
                $lines[] = ['particulars' => $this->particulars('4050', $creditNote->remarks ?: "Credit Note To Customer, {$customerName}"), 'debit' => (float) $creditNote->subtotal, 'credit' => 0.0, 'taxCode' => null];
            } else {
                foreach ($creditNote->items as $item) {
                    $lines[] = ['particulars' => $this->particulars('4050', $item->item_name), 'debit' => (float) $item->amount, 'credit' => 0.0, 'taxCode' => null];
                }
            }
        }

        if ((float) $creditNote->tax_amount > 0) {
            $lines[] = ['particulars' => $this->particulars('2100', 'Tax'), 'debit' => (float) $creditNote->tax_amount, 'credit' => 0.0, 'taxCode' => null];
        }

        if ((float) $creditNote->discount_amount > 0) {
            $lines[] = ['particulars' => $this->particulars('4900', 'Discount'), 'debit' => 0.0, 'credit' => (float) $creditNote->discount_amount, 'taxCode' => null];
        }

        return $this->toRows($creditNote->document_number, $date, $ref1, $lines, $salesmanCode, $branchCode);
    }

    /** "{code} - {name} - [{remark}]", same convention as JournalListExport::particulars(). */
    protected function particulars(string $code, string $remark): string
    {
        $name = $this->accountNames[$code] ?? $code;

        return "{$code} - {$name} - [{$remark}]";
    }

    /** @param array<int, array{particulars: string, debit: float, credit: float, taxCode: ?string}> $lines */
    protected function toRows(string $transaction, ?string $date, ?string $ref1, array $lines, ?string $salesmanCode, ?string $branchCode): array
    {
        $rows = [];

        foreach ($lines as $index => $line) {
            $debit = (float) $line['debit'];
            $credit = (float) $line['credit'];

            $this->totalDebit += $debit;
            $this->totalCredit += $credit;
            $this->rowCount++;

            $rows[] = [
                $index === 0 ? $transaction : null,
                $date,
                $ref1,
                $line['particulars'],
                $debit,
                $credit,
                $line['taxCode'],
                $salesmanCode,
                null, // Department Code — no such concept anywhere in this schema
                null, // Project Code — no such concept anywhere in this schema
                $branchCode,
            ];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->insertNewRowBefore(1, 6);

                $sheet->setCellValue('A1', 'JOURNAL LIST');
                $sheet->setCellValue('A2', 'PT. KALINDO ETAM');
                $sheet->setCellValue('A3', ($this->dateFrom ? Carbon::parse($this->dateFrom)->format('d/m/Y') : '-') . ' - ' . ($this->dateTo ? Carbon::parse($this->dateTo)->format('d/m/Y') : '-'));
                $sheet->setCellValue('E3', now()->format('Y-m-d h:i:s A'));
                $sheet->fromArray([[
                    'Transaction', 'Date', 'Ref. 1 #', 'Particulars', 'Debit', 'Credit', 'Tax Code', 'Salesman Code', 'Department Code', 'Project Code', 'Branch Code',
                ]], null, 'A5');
                $sheet->setCellValue('A6', $this->groupLabel);

                $trailerRow = 6 + $this->rowCount + 1;
                $sheet->setCellValue("A{$trailerRow}", "Total For :[{$this->groupLabel}]");
                $sheet->setCellValue("E{$trailerRow}", $this->totalDebit);
                $sheet->setCellValue("F{$trailerRow}", $this->totalCredit);
            },
        ];
    }
}
