<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\PurchaseByItemExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPurchaseByItemRequest;
use App\Http\Resources\PurchaseByItemRowResource;
use App\Services\PurchaseByItemService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Purchase Report's By Item tab — read-only, purchases actually received (Goods Receipt), net of Returns, per item, plus price monitoring. */
class PurchaseByItemController extends Controller
{
    use ApiResponse;

    public function __construct(protected PurchaseByItemService $purchaseByItemService) {}

    public function index(IndexPurchaseByItemRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(PurchaseByItemRowResource::collection($this->purchaseByItemService->list($filters, $perPage)));
    }

    /** Transaction history drill-down — replaces the legacy "Product Purchase History Price" report. */
    public function history(IndexPurchaseByItemRequest $request, string $itemId): JsonResponse
    {
        return $this->success($this->purchaseByItemService->history($itemId, $request->validated()));
    }

    /** "Data Import Historis" section — completed Product Purchase Report imports, kept separate from the live table above. */
    public function importSnapshots(): JsonResponse
    {
        return $this->success($this->purchaseByItemService->importSnapshots());
    }

    public function export(IndexPurchaseByItemRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new PurchaseByItemExport($this->purchaseByItemService->exportRows($filters, $format));
        $fileName = $this->purchaseByItemService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
