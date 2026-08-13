<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * E1 (UAT review 2026-08-12) — mirrors the shape of the frontend's existing
 * CSV builder (journalListCsv.ts) exactly: one row per group header, one per
 * line, one per group subtotal, then a grand total — same column order.
 */
class JournalListExport implements FromArray, WithHeadings
{
    /** @param array{groups: array<int, array{label: string, rows: array, subtotal: array{debit: float, credit: float}}>, grand_total: array{debit: float, credit: float}} $report */
    public function __construct(protected array $report) {}

    public function headings(): array
    {
        return ['Group', 'Transaction', 'Date', 'Ref #', 'Particulars', 'Debit', 'Credit'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->report['groups'] as $group) {
            $rows[] = [$group['label'], '', '', '', '', '', ''];

            foreach ($group['rows'] as $row) {
                $rows[] = ['', $row['transaction'] ?? '', $row['date'] ?? '', $row['ref_no'] ?? '', $row['particulars'], (float) $row['debit'], (float) $row['credit']];
            }

            $rows[] = ["Total For: {$group['label']}", '', '', '', '', (float) $group['subtotal']['debit'], (float) $group['subtotal']['credit']];
        }

        $rows[] = ['Grand Total', '', '', '', '', (float) $this->report['grand_total']['debit'], (float) $this->report['grand_total']['credit']];

        return $rows;
    }
}
