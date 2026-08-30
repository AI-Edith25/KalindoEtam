<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SalesListingExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSalesListingRequest;
use App\Http\Resources\SalesListingRowResource;
use App\Services\SalesListingService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Sales Report's Sales Listing tab — read-only, one row per Invoice or Credit Note document. */
class SalesListingController extends Controller
{
    use ApiResponse;

    public function __construct(protected SalesListingService $salesListingService) {}

    public function index(IndexSalesListingRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(
            SalesListingRowResource::collection($this->salesListingService->list($filters, $perPage)),
            extraMeta: ['kpis' => $this->salesListingService->kpis($filters)],
        );
    }

    public function export(IndexSalesListingRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new SalesListingExport($this->salesListingService->exportRows($filters, $format));
        $fileName = $this->salesListingService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
