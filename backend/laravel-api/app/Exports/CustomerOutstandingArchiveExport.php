<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Flat export of the currently filtered/displayed archive rows -- one physical row per invoice
 * line (Customer Code/Name denormalized onto every row, since CSV/XLSX can't reproduce the
 * source file's grouped-with-blank-cells layout), plus a trailing Grand Total row.
 */
class CustomerOutstandingArchiveExport implements FromArray, WithCustomCsvSettings, WithHeadings, WithStrictNullComparison
{
    protected const HEADINGS = [
        'Customer Code', 'Customer Name', 'Date', 'Ref. No', 'Invoice Amt', 'Paid Amount',
        'Unpaid Amount', 'Terms (Days)', 'Due Date', 'Overdue Amount', 'Overdue (Days)', 'Status',
    ];

    /** @param array<int, array{customer_code: string, customer_name: string, rows: array}> $customers */
    public function __construct(protected array $customers, protected float $grandTotalUnpaid, protected float $grandTotalOverdue) {}

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
                    $line['txn_date'],
                    $line['ref_no'],
                    $line['invoice_amount'],
                    $line['paid_amount'],
                    $line['unpaid_amount'],
                    $line['terms_days'],
                    $line['due_date'],
                    $line['overdue_amount'],
                    $line['overdue_days'],
                    ucfirst($line['status']),
                ];
            }
        }

        $rows[] = ['', '', '', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Grand Total', '', '', '', '', '', $this->grandTotalUnpaid, '', '', $this->grandTotalOverdue, '', ''];

        return $rows;
    }

    public function getCsvSettings(): array
    {
        return ['use_bom' => true, 'delimiter' => ','];
    }
}
