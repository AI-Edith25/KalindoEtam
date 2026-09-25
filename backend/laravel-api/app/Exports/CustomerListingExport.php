<?php

namespace App\Exports;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Maintenance > Customers "Export" button — replicates SkyBiz's xlsCustomerListing.xlsx layout
 * (banner rows 1-2, blank row 3, header row 4, type/length hint row 5, data from row 6) with this
 * app's own Customer fields. No other export shares this banner/hint-row layout, so the styling
 * lives directly in this class rather than a shared trait (see StylesLegacyReportSheet for the
 * "many exports, one trait" version of this problem — deliberately not reused here).
 *
 * WithStrictNullComparison is required, not decorative — PhpSpreadsheet's Worksheet::fromArray()
 * defaults to loose (==) null comparison and silently skips writing any cell whose value equals
 * null under `==`, which a real 0.00 Credit Limit does in PHP (same bug class fixed once already
 * in the Sales Invoice export, commit b005ba5).
 */
class CustomerListingExport implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    private const HEADER_ROW = 4;

    private const HINT_ROW = 5;

    private const FIRST_DATA_ROW = 6;

    /** Column => [header, hint, required]. */
    private const COLUMNS = [
        'A' => ['CusCode', '255 (t)', true],
        'B' => ['CusName', '255 (t)', true],
        'C' => ['Address', '255 (t)', false],
        'D' => ['AreaCode', '255 (t)', false],
        'E' => ['TermCode', '255 (t)', false],
        'F' => ['CreditLimit', '15 (n)', false],
        'G' => ['SalesPersonCode', '255 (t)', false],
        'H' => ['Phone', '50 (t)', false],
        'I' => ['Tel', '50 (t)', false],
        'J' => ['Email', '255 (t)', false],
        'K' => ['NoKTP', '50 (t)', false],
        'L' => ['NPWPNo', '50 (t)', false],
        'M' => ['Status', '10 (t)', false],
    ];

    private const COLUMN_WIDTHS = [
        'A' => 12, 'B' => 45, 'C' => 60, 'D' => 18, 'E' => 14, 'F' => 16,
        'G' => 20, 'H' => 18, 'I' => 18, 'J' => 30, 'K' => 22, 'L' => 24, 'M' => 10,
    ];

    /** @param Collection<int, Customer> $customers */
    public function __construct(protected Collection $customers) {}

    public function title(): string
    {
        return 'Customer';
    }

    public function array(): array
    {
        $rows = [];
        $rows[1] = ['CUSTOMER / DEBTORS', null, 'Advise: Maximum data store not more than 10000 records', null, null, null, null, null, null, null, null, null, null];
        $rows[2] = ['**  t = text   **  n = number  ** Date= dd/mm/yyyy', null, null, null, 'compulsary field', null, null, null, null, null, null, null, null];
        $rows[3] = array_fill(0, 13, null);
        $rows[self::HEADER_ROW] = array_map(fn ($column) => $column[0], array_values(self::COLUMNS));
        $rows[self::HINT_ROW] = array_map(fn ($column) => $column[1], array_values(self::COLUMNS));

        $rowNumber = self::FIRST_DATA_ROW;
        foreach ($this->customers as $customer) {
            $rows[$rowNumber] = [
                $customer->customer_code,
                $customer->customer_name,
                $customer->address,
                $customer->area,
                $customer->termsOfPayment?->code,
                (float) ($customer->credit_limit ?? 0),
                $customer->salesPerson?->name,
                $customer->phone,
                $customer->telephone,
                $customer->email,
                $customer->no_ktp,
                $customer->no_npwp,
                $customer->is_active ? 'Active' : 'Inactive',
            ];
            $rowNumber++;
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->getSheet()->getDelegate();
                $lastRow = self::FIRST_DATA_ROW + $this->customers->count() - 1;
                $lastColumn = array_key_last(self::COLUMNS);

                $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);

                $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle("A2:{$lastColumn}2")->getFont()->setBold(true)->setSize(10);

                $sheet->getStyle("A" . self::HEADER_ROW . ":{$lastColumn}" . self::HEADER_ROW)
                    ->getFont()->setBold(true)->setSize(9);
                $sheet->getStyle("A" . self::HEADER_ROW . ":{$lastColumn}" . self::HEADER_ROW)
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("A" . self::HINT_ROW . ":{$lastColumn}" . self::HINT_ROW)
                    ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                foreach (self::COLUMNS as $column => [$header, $hint, $required]) {
                    if ($required) {
                        $sheet->getStyle("{$column}" . self::HEADER_ROW)->getFont()->getColor()->setARGB(Color::COLOR_RED);
                    }
                    $sheet->getColumnDimension($column)->setWidth(self::COLUMN_WIDTHS[$column]);
                }

                if ($lastRow >= self::FIRST_DATA_ROW) {
                    $sheet->getStyle("C" . self::FIRST_DATA_ROW . ":C{$lastRow}")->getAlignment()->setWrapText(true);
                    $sheet->getStyle("F" . self::FIRST_DATA_ROW . ":F{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle("H" . self::FIRST_DATA_ROW . ":H{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }
            },
        ];
    }
}
