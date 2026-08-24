<?php

namespace App\Exports;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Journal List export — reproduces the legacy xlsJournalList(Cashbook/OR/PV).xlsx
 * layout (verified against the real files at the project root, see the
 * Journal List rework plan), minus the Tax/Salesman/Department/Project/Branch
 * Code columns (dropped per user request — none of them ever carried data,
 * this system has no such fields) and with "Ref. 1 #" relabeled "Notes":
 * 5 preamble rows, a group-header row, one physical row per journal line (not
 * per voucher — Transaction/voucher number only appears on a line's first
 * row, Date/Notes repeat on every line), then a "Total For :[...]" trailer
 * with Debit==Credit.
 *
 * FromQuery (not FromCollection) + WithChunkReading streams the underlying
 * journal_entries query in bounded batches instead of loading the whole
 * filtered set at once — the real Cashbook export is 100k+ physical rows.
 * Header/footer rows are inserted once in AfterSheet (fires after every
 * chunk has been written), using totals accumulated during mapping instead
 * of a second aggregate query.
 */
class JournalListExport implements FromQuery, WithChunkReading, WithMapping, WithEvents
{
    protected float $totalDebit = 0.0;
    protected float $totalCredit = 0.0;
    protected int $rowCount = 0;

    public function __construct(
        protected Builder $query,
        protected string $groupLabel,
        protected ?string $dateFrom = null,
        protected ?string $dateTo = null,
    ) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    /** @return array<int, array> One physical spreadsheet row per journal line — Maatwebsite Excel accepts an array of rows from a single map() call. */
    public function map($journalEntry): array
    {
        /** @var JournalEntry $journalEntry */
        $date = $journalEntry->posting_date?->format('d/m/Y');
        $notes = $journalEntry->resolved_reference_number;

        $lines = $journalEntry->lines;
        $rows = [];

        foreach ($lines as $index => $line) {
            /** @var JournalEntryLine $line */
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;

            $this->totalDebit += $debit;
            $this->totalCredit += $credit;
            $this->rowCount++;

            $rows[] = [
                $index === 0 ? $journalEntry->resolved_document_number : null,
                $date,
                $notes,
                $this->particulars($line, $lines),
                $debit > 0 ? $debit : null,
                $credit > 0 ? $credit : null,
            ];
        }

        return $rows;
    }

    /**
     * "{code} - {name} - [{remark}]" per the legacy layout. `{remark}` is the
     * line's own description when it has one; a line the posting business
     * module left undescribed (typically the cash/bank leg — see
     * ReceiptEntry::journalLines()) falls back to summarizing its sibling
     * lines, the same reconstruction the pre-rework JournalListService used
     * (E2, UAT review 2026-08-12) for exactly this "who/what is this cash
     * movement for" purpose.
     */
    protected function particulars(JournalEntryLine $line, $siblings): string
    {
        $account = $line->chartOfAccount;
        $remark = $line->description;

        if (! $remark) {
            $remark = $siblings
                ->reject(fn (JournalEntryLine $sibling) => $sibling->id === $line->id)
                ->map(fn (JournalEntryLine $sibling) => $sibling->chartOfAccount->name . ($sibling->description ? " ({$sibling->description})" : ''))
                ->implode('; ');
        }

        return "{$account->code} - {$account->name} - [{$remark}]";
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Preamble (1-4) + column headers (5) + group header (6), pushing the already-written
                // journal lines down from row 1 to row 7.
                $sheet->insertNewRowBefore(1, 6);

                $sheet->setCellValue('A1', 'JOURNAL LIST');
                $sheet->setCellValue('A2', 'PT. KALINDO ETAM');
                $sheet->setCellValue('A3', ($this->dateFrom ? Carbon::parse($this->dateFrom)->format('d/m/Y') : '-') . ' - ' . ($this->dateTo ? Carbon::parse($this->dateTo)->format('d/m/Y') : '-'));
                $sheet->setCellValue('E3', now()->format('Y-m-d h:i:s A'));
                $sheet->fromArray([[
                    'Transaction', 'Date', 'Notes', 'Particulars', 'Debit', 'Credit',
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
