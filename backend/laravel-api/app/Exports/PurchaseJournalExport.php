<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Purchase Journal's export — Purchase Invoice / Purchase Return sub-tabs. Same posture as
 * SalesJournalExport (walks source documents, not journal_entries — see that class's own
 * docblock for why). Two real schema gaps vs. Sales, both by explicit user decision:
 *
 * - No Tax Code column at all (10 columns, not 11) — neither purchase_invoice_items nor
 *   purchase_return_items has a tax_id/tax_amount column, so no purchase line item has ever
 *   carried a stored tax code; the column is dropped entirely rather than shipped always-blank.
 * - Salesman Code and Branch Code are always null — Purchase has no salesperson concept, and
 *   PurchaseInvoice/PurchaseReturn have no branch_id column at all (confirmed via their
 *   migrations/fillable), unlike Sales. Department Code/Project Code are always null on every
 *   tab, Sales included — no such concept exists anywhere in this schema.
 */
class PurchaseJournalExport implements FromQuery, WithChunkReading, WithMapping, WithEvents
{
    protected const ACCOUNTS = ['2000', '5100', '5050', '2100'];

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

    /** @return array<int, array> */
    public function map($document): array
    {
        return $this->view === 'purchase_return' ? $this->mapReturn($document) : $this->mapInvoice($document);
    }

    /**
     * Header: AP credit for grand_total. One Purchase Expense debit row per PurchaseInvoiceItem
     * when items exist; a single row for the whole subtotal otherwise. Tax is always a single
     * header-level row (tax_amount is a manual header figure — Goods Receipt items carry no tax
     * snapshot to derive a per-item split from, per PurchaseInvoice::journalLines()'s own
     * docblock).
     */
    protected function mapInvoice(PurchaseInvoice $purchaseInvoice): array
    {
        $date = $purchaseInvoice->invoice_date?->format('d/m/Y');
        $ref1 = $purchaseInvoice->reference_number;
        $supplierName = $purchaseInvoice->supplier->supplier_name;

        $lines = [];
        $lines[] = ['particulars' => $this->particulars('2000', "Purchases, {$supplierName}"), 'debit' => 0.0, 'credit' => (float) $purchaseInvoice->grand_total];

        if ($purchaseInvoice->items->isEmpty()) {
            if ((float) $purchaseInvoice->subtotal > 0) {
                $lines[] = ['particulars' => $this->particulars('5100', $purchaseInvoice->remarks ?: "Purchases, {$supplierName}"), 'debit' => (float) $purchaseInvoice->subtotal, 'credit' => 0.0];
            }
        } else {
            foreach ($purchaseInvoice->items as $item) {
                $lines[] = ['particulars' => $this->particulars('5100', $item->item_name), 'debit' => (float) $item->amount, 'credit' => 0.0];
            }
        }

        if ((float) $purchaseInvoice->tax_amount > 0) {
            $lines[] = ['particulars' => $this->particulars('2100', 'Tax'), 'debit' => (float) $purchaseInvoice->tax_amount, 'credit' => 0.0];
        }

        return $this->toRows($purchaseInvoice->document_number, $date, $ref1, $lines);
    }

    /** Mirrors mapInvoice() reversed, contra account 5050 — same shape as PurchaseReturn::journalLines(). Ref.1# is inherited from the parent Purchase Invoice (PurchaseReturn has no reference field of its own). */
    protected function mapReturn(PurchaseReturn $purchaseReturn): array
    {
        $date = $purchaseReturn->return_date?->format('d/m/Y');
        $ref1 = $purchaseReturn->purchaseInvoice?->reference_number;
        $supplierName = $purchaseReturn->supplier->supplier_name;

        $lines = [];
        $lines[] = ['particulars' => $this->particulars('2000', "Purchase Return, {$supplierName}"), 'debit' => (float) $purchaseReturn->total_amount, 'credit' => 0.0];

        if ((float) $purchaseReturn->subtotal > 0) {
            if ($purchaseReturn->items->isEmpty()) {
                $lines[] = ['particulars' => $this->particulars('5050', $purchaseReturn->remarks ?: "Purchase Return, {$supplierName}"), 'debit' => 0.0, 'credit' => (float) $purchaseReturn->subtotal];
            } else {
                foreach ($purchaseReturn->items as $item) {
                    $lines[] = ['particulars' => $this->particulars('5050', $item->item_name), 'debit' => 0.0, 'credit' => (float) $item->amount];
                }
            }
        }

        if ((float) $purchaseReturn->tax_amount > 0) {
            $lines[] = ['particulars' => $this->particulars('2100', 'Tax'), 'debit' => 0.0, 'credit' => (float) $purchaseReturn->tax_amount];
        }

        return $this->toRows($purchaseReturn->document_number, $date, $ref1, $lines);
    }

    /** "{code} - {name} - [{remark}]", same convention as SalesJournalExport/JournalListExport. */
    protected function particulars(string $code, string $remark): string
    {
        $name = $this->accountNames[$code] ?? $code;

        return "{$code} - {$name} - [{$remark}]";
    }

    /** @param array<int, array{particulars: string, debit: float, credit: float}> $lines */
    protected function toRows(string $transaction, ?string $date, ?string $ref1, array $lines): array
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
                null, // Salesman Code — no concept for Purchase
                null, // Department Code — no such concept anywhere in this schema
                null, // Project Code — no such concept anywhere in this schema
                null, // Branch Code — no concept for Purchase (no branch_id column on either document)
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
                    'Transaction', 'Date', 'Ref. 1 #', 'Particulars', 'Debit', 'Credit', 'Salesman Code', 'Department Code', 'Project Code', 'Branch Code',
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
