<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ProductSalesExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexProductSalesRequest;
use App\Http\Resources\ProductSalesRowResource;
use App\Services\ProductSalesService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Sales Report's Product Sales tab — read-only, one row per item (or Item Group). */
class ProductSalesController extends Controller
{
    use ApiResponse;

    public function __construct(protected ProductSalesService $productSalesService) {}

    public function index(IndexProductSalesRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(
            ProductSalesRowResource::collection($this->productSalesService->list($filters, $perPage)),
            extraMeta: ['kpis' => $this->productSalesService->kpis($filters)],
        );
    }

    public function customers(IndexProductSalesRequest $request, string $itemId): JsonResponse
    {
        return $this->success($this->productSalesService->customersForItem($itemId, $request->validated()));
    }

    public function export(IndexProductSalesRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new ProductSalesExport($this->productSalesService->exportRows($filters, $format));
        $fileName = $this->productSalesService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
