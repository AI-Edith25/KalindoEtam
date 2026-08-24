<?php

namespace App\Services;

use App\Exports\Concerns\BuildsLegacyReportRows;
use App\Models\Invoice;
use App\Repositories\CustomerSalesRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CustomerSalesService
{
    use BuildsLegacyReportRows;

    public function __construct(protected CustomerSalesRepository $customerSalesRepository) {}

    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->customerSalesRepository->paginate($filters, $filters['sort'] ?? 'amount', $filters['sort_dir'] ?? 'desc', $perPage);
    }

    public function kpis(array $filters): array
    {
        return $this->customerSalesRepository->kpis($filters);
    }

    public function achievement(array $filters): Collection
    {
        return $this->customerSalesRepository->achievement($filters);
    }

    /** @return array{documents: array<int, array<string, mixed>>, subtotal: array{amount: float, tax_amount: float, amount_incl_tax: float}} */
    public function documentsForCustomer(string $customerId, array $filters): array
    {
        $invoices = $this->customerSalesRepository->documentsForCustomer($customerId, $filters);

        $documents = $invoices->map(fn (Invoice $invoice) => [
            'id' => $invoice->id,
            'date' => $invoice->invoice_date?->format('Y-m-d'),
            'document_number' => $invoice->document_number,
            'reference_so_number' => $invoice->salesOrder?->document_number,
            'type' => $invoice->invoice_type?->value,
            'amount' => (float) $invoice->subtotal,
            'tax_amount' => (float) $invoice->tax_amount,
            'amount_incl_tax' => (float) $invoice->subtotal + (float) $invoice->tax_amount,
        ])->values()->all();

        return [
            'documents' => $documents,
            'subtotal' => [
                'amount' => (float) $invoices->sum('subtotal'),
                'tax_amount' => (float) $invoices->sum('tax_amount'),
                'amount_incl_tax' => (float) $invoices->sum('subtotal') + (float) $invoices->sum('tax_amount'),
            ],
        ];
    }

    /** @return array{rows: array<int, array<int, mixed>>, meta: array<string, mixed>} */
    public function exportRows(array $filters, string $format): array
    {
        $rows = $this->customerSalesRepository->allGrouped($filters, $filters['sort'] ?? 'amount', $filters['sort_dir'] ?? 'desc');
        $kpis = $this->kpis($filters);
        $totalRevenue = $kpis['total_revenue'] ?: 1;

        $headingRow = ['CUSTOMER CODE', 'CUSTOMER NAME', 'BRANCH', 'SALES PERSON', 'TRANSACTIONS', 'TOTAL QTY', 'AMOUNT EXCL. TAX', 'TAX', 'AMOUNT INCL. TAX', '% OF REVENUE', 'LAST TRANSACTION'];

        $bodyRows = $rows->map(function ($row) use ($totalRevenue) {
            $amount = (float) $row->amount;
            $tax = (float) $row->tax_amount;
            $pct = round($amount / $totalRevenue * 100, 2);
            $lastDate = $row->last_transaction_date ? Carbon::parse($row->last_transaction_date) : null;

            return [
                $row->customer_code, $row->customer_name, $row->branch_name ?? 'Multiple', $row->sales_person_name ?? 'Multiple',
                (int) $row->transaction_count, (int) $row->qty, $amount, $tax, $amount + $tax, $pct,
                $lastDate ? $this->excelDate($lastDate) : null,
            ];
        })->all();

        $totalsRow = [
            'Grand Total', '', '', '', $rows->sum('transaction_count'), $rows->sum('qty'),
            $kpis['total_revenue'], $kpis['total_tax'], $kpis['total_incl_tax'], 100.0, null,
        ];

        if ($format === 'csv') {
            return $this->buildCsvRows($headingRow, $bodyRows, 'K');
        }

        return $this->buildXlsxRows(
            title: 'CUSTOMER SALES REPORT',
            periodLabel: $this->periodLabel($filters),
            headingRow: $headingRow,
            bodyRows: $bodyRows,
            totalsRow: $totalsRow,
            lastColumn: 'K',
            numberFormatColumns: ['G', 'H', 'I', 'J'],
            dateColumn: 'K',
            rightAlignColumns: ['E', 'F', 'G', 'H', 'I', 'J'],
        );
    }

    public function fileName(array $filters, string $format): string
    {
        return $this->buildFileName('CustomerSalesReport', $filters['date_from'] ?? null, $filters['date_to'] ?? null, $format);
    }

    /** Real Excel date serial — see JournalListExport/SalesReportService for the same pattern; PhpSpreadsheet's value binder doesn't auto-convert \DateTimeInterface. */
    protected function excelDate(Carbon $date): float
    {
        return \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date);
    }

    protected function periodLabel(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        return ($from ? Carbon::parse($from)->format('d/m/Y') : '-') . ' - ' . ($to ? Carbon::parse($to)->format('d/m/Y') : '-');
    }
}
