<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\PurchaseJournalExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPurchaseJournalRequest;
use App\Http\Resources\PurchaseJournalRowResource;
use App\Services\PurchaseJournalService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Journal List's Purchase Journal tab — Purchase Invoice/Purchase Return sub-tabs. Same screen/export split as SalesJournalController. */
class PurchaseJournalController extends Controller
{
    use ApiResponse;

    public function __construct(protected PurchaseJournalService $purchaseJournalService) {}

    public function index(IndexPurchaseJournalRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $view = $filters['view'] ?? 'purchase_invoice';
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(PurchaseJournalRowResource::collection(
            $this->purchaseJournalService->list($filters, $view, $perPage)
        ));
    }

    public function export(IndexPurchaseJournalRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $view = $filters['view'] ?? 'purchase_invoice';
        $format = $filters['format'] ?? 'xlsx';

        $export = new PurchaseJournalExport(
            $this->purchaseJournalService->exportQuery($filters, $view),
            $view,
            $this->purchaseJournalService->groupLabel($view),
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        $fileName = 'JournalList-' . $this->purchaseJournalService->fileNameSegment($view) . '-' . now()->format('dmY') . ".{$format}";

        return Excel::download($export, $fileName);
    }
}
