<?php

namespace App\Services;

use App\Repositories\SalesJournalRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Resolves Sales Journal's screen pagination + export collection + labels for both sub-tabs (Sales Invoice, Credit Note). */
class SalesJournalService
{
    protected const GROUP_LABELS = ['invoice' => 'Sales Journal', 'credit_note' => 'Sales Return Journal'];

    protected const FILE_NAME_SEGMENTS = ['invoice' => 'SalesInvoice', 'credit_note' => 'SalesReturn'];

    public function __construct(protected SalesJournalRepository $salesJournalRepository) {}

    public function list(array $filters, string $view, int $perPage = 25): LengthAwarePaginator
    {
        return $view === 'credit_note'
            ? $this->salesJournalRepository->paginateCreditNotes($filters, $perPage)
            : $this->salesJournalRepository->paginateInvoices($filters, $perPage);
    }

    public function exportQuery(array $filters, string $view): Builder
    {
        return $view === 'credit_note'
            ? $this->salesJournalRepository->exportCreditNoteQuery($filters)
            : $this->salesJournalRepository->exportInvoiceQuery($filters);
    }

    public function groupLabel(string $view): string
    {
        return self::GROUP_LABELS[$view] ?? self::GROUP_LABELS['invoice'];
    }

    public function fileNameSegment(string $view): string
    {
        return self::FILE_NAME_SEGMENTS[$view] ?? self::FILE_NAME_SEGMENTS['invoice'];
    }
}
