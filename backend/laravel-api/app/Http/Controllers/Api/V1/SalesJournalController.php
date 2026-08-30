<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SalesJournalExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSalesJournalRequest;
use App\Http\Resources\SalesListingRowResource;
use App\Services\SalesJournalService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Journal List's Sales Journal tab — Sales Invoice/Credit Note sub-tabs. Screen is document-level
 * (reuses SalesListingRowResource — SalesJournalRepository's screen query is
 * SalesListingRepository::query() pinned to one type, same row shape). Export is item-level,
 * synthesized from source documents (see SalesJournalExport) — a different shape entirely, same
 * split as CashBookController (screen) vs JournalListController (export).
 */
class SalesJournalController extends Controller
{
    use ApiResponse;

    public function __construct(protected SalesJournalService $salesJournalService) {}

    public function index(IndexSalesJournalRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $view = $filters['view'] ?? 'invoice';
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(SalesListingRowResource::collection(
            $this->salesJournalService->list($filters, $view, $perPage)
        ));
    }

    public function export(IndexSalesJournalRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $view = $filters['view'] ?? 'invoice';
        $format = $filters['format'] ?? 'xlsx';

        $export = new SalesJournalExport(
            $this->salesJournalService->exportQuery($filters, $view),
            $view,
            $this->salesJournalService->groupLabel($view),
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        $fileName = 'JournalList-' . $this->salesJournalService->fileNameSegment($view) . '-' . now()->format('dmY') . ".{$format}";

        return Excel::download($export, $fileName);
    }
}
