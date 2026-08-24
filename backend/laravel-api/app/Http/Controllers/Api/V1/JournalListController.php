<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\JournalListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexJournalListRequest;
use App\Services\JournalListService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Journal List's export only — journal-line-level, matching the legacy
 * xlsJournalList(*).xlsx files (see JournalListExport). Screen data (one row
 * per document) lives on CashBookController instead.
 */
class JournalListController extends Controller
{
    public function __construct(protected JournalListService $journalListService) {}

    public function export(IndexJournalListRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $view = $filters['view'] ?? 'all';
        $format = $filters['format'] ?? 'xlsx';

        $export = new JournalListExport(
            $this->journalListService->exportQuery($filters, $view),
            $this->journalListService->groupLabel($view),
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        return Excel::download($export, "journal-list.{$format}");
    }
}
