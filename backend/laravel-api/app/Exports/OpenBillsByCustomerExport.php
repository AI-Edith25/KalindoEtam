<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Customer Outstanding Bills (live) export -- one row per still-open document, customer code/name
 * repeated on every row so the file can be filtered or pivoted per customer. Totals come from the
 * same payload the on-screen list uses, never recomputed here.
 */
class OpenBillsByCustomerExport implements FromArray, WithHeadings
{
    protected const HEADINGS = [
        'Customer Code', 'Customer Name', 'Tanggal', 'No. Dokumen', 'Referensi', 'Jumlah Invoice',
        'Dibayar', 'Sisa', 'Terms (Hari)', 'Jatuh Tempo', 'Overdue Amount', 'Hari Terlewat',
    ];

    /** @param iterable<int, array{customer_code: ?string, customer_name: ?string, rows: iterable<int, array>}> $customers */
    public function __construct(
        protected iterable $customers,
        protected float $grandTotalUnpaid,
        protected float $grandTotalOverdue,
        protected string $asAtDate,
    ) {}

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->customers as $customer) {
            foreach ($customer['rows'] as $line) {
                $rows[] = [
                    $customer['customer_code'],
                    $customer['customer_name'],
                    $line['invoice_date'],
                    $line['document_number'],
                    $line['reference_1'],
                    $line['amount'],
                    $line['paid_amount'],
                    $line['unpaid_amount'],
                    $line['terms_days'],
                    $line['due_date'],
                    $line['overdue_amount'],
                    $line['overdue_days'],
                ];
            }
        }

        $rows[] = ['', '', '', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Grand Total', "Per {$this->asAtDate}", '', '', '', '', '', $this->grandTotalUnpaid, '', '', $this->grandTotalOverdue, ''];

        return $rows;
    }
}
