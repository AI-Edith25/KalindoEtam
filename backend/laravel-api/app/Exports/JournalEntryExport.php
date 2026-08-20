<?php

namespace App\Exports;

use App\Models\JournalEntry;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * General Journal's Export — same columns as the on-screen table
 * (JournalEntryListPage.tsx), same row set as JournalEntryService::listAll()
 * (every filtered row, not one page). One class handles both XLSX and CSV —
 * Excel::download() picks the writer from the filename extension the
 * controller passes.
 */
class JournalEntryExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Journal Number', 'Posting Date', 'Reference Type', 'Reference Number',
            'Status', 'Total Debit', 'Total Credit', 'Created By',
        ];
    }

    public function map($row): array
    {
        /** @var JournalEntry $row */
        return [
            $row->document_number,
            $row->posting_date?->format('Y-m-d'),
            // Same computation as JournalEntryResource::reference_label, with the list page's null fallback.
            $row->reference_type ? ucwords(str_replace('_', ' ', $row->reference_type)) : 'Manual',
            $row->referenceDocument?->document_number,
            // Matches StatusBadge's on-screen label — the list page maps 'submitted' to 'Posted'.
            $row->status?->value === 'submitted' ? 'Posted' : ucfirst($row->status?->value ?? ''),
            (float) $row->total_debit,
            (float) $row->total_credit,
            $row->creator?->name,
        ];
    }
}
