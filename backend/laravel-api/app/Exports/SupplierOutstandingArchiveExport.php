<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;

/** AP mirror of CustomerOutstandingArchiveExport. */
class SupplierOutstandingArchiveExport implements FromArray, WithCustomCsvSettings, WithEvents, WithHeadings, WithStrictNullComparison
{
    protected const HEADINGS = [
        'Supplier Code', 'Supplier Name', 'Date', 'Ref. No', 'Invoice Amt', 'Paid Amount',
        'Unpaid Amount', 'Terms (Days)', 'Due Date', 'Overdue Amount', 'Overdue (Days)', 'Status',
    ];

    /** @param array<int, array{supplier_code: string, supplier_name: string, rows: array}> $suppliers */
    public function __construct(
        protected array $suppliers,
        protected float $grandTotalUnpaid,
        protected float $grandTotalOverdue,
        protected string $snapshotAsOfDate,
    ) {}

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->suppliers as $supplier) {
            foreach ($supplier['rows'] as $line) {
                $rows[] = [
                    $supplier['supplier_code'],
                    $supplier['supplier_name'],
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

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->setCellValue('A1', "Sumber: import manual per {$this->snapshotAsOfDate} -- bukan data live.");
            },
        ];
    }

    public function getCsvSettings(): array
    {
        return ['use_bom' => true, 'delimiter' => ','];
    }
}
