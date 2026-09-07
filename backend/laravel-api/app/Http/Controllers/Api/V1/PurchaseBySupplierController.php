<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\PurchaseBySupplierExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPurchaseBySupplierRequest;
use App\Http\Resources\PurchaseBySupplierRowResource;
use App\Services\PurchaseBySupplierService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Purchase Report's By Supplier tab — read-only, purchases actually received (Goods Receipt), net of Returns, per supplier. */
class PurchaseBySupplierController extends Controller
{
    use ApiResponse;

    public function __construct(protected PurchaseBySupplierService $purchaseBySupplierService) {}

    public function index(IndexPurchaseBySupplierRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(
            PurchaseBySupplierRowResource::collection($this->purchaseBySupplierService->list($filters, $perPage)),
            extraMeta: ['kpis' => $this->purchaseBySupplierService->kpis($filters)],
        );
    }

    public function export(IndexPurchaseBySupplierRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new PurchaseBySupplierExport($this->purchaseBySupplierService->exportRows($filters, $format));
        $fileName = $this->purchaseBySupplierService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
