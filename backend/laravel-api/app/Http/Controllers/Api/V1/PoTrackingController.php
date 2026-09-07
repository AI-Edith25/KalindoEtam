<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\PoTrackingExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPoTrackingRequest;
use App\Http\Resources\PoTrackingRowResource;
use App\Services\PoTrackingService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Purchase Report's PO Tracking tab — read-only, submitted Purchase Orders whose Goods Receipts haven't fully arrived yet. */
class PoTrackingController extends Controller
{
    use ApiResponse;

    public function __construct(protected PoTrackingService $poTrackingService) {}

    public function index(IndexPoTrackingRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(PoTrackingRowResource::collection($this->poTrackingService->list($filters, $perPage)));
    }

    /** Per-item breakdown drill-down for one PO. */
    public function items(string $purchaseOrderId): JsonResponse
    {
        return $this->success($this->poTrackingService->items($purchaseOrderId));
    }

    public function export(IndexPoTrackingRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new PoTrackingExport($this->poTrackingService->exportRows($filters, $format));
        $fileName = $this->poTrackingService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
