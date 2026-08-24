<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\CustomerSalesExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCustomerSalesRequest;
use App\Http\Resources\CustomerSalesRowResource;
use App\Services\CustomerSalesService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Sales Report's Customer Sales tab — read-only, one row per customer, plus the Sales Achievement by Sales Person panel. */
class CustomerSalesController extends Controller
{
    use ApiResponse;

    public function __construct(protected CustomerSalesService $customerSalesService) {}

    public function index(IndexCustomerSalesRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(
            CustomerSalesRowResource::collection($this->customerSalesService->list($filters, $perPage)),
            extraMeta: ['kpis' => $this->customerSalesService->kpis($filters)],
        );
    }

    public function documents(IndexCustomerSalesRequest $request, string $customerId): JsonResponse
    {
        return $this->success($this->customerSalesService->documentsForCustomer($customerId, $request->validated()));
    }

    public function achievement(IndexCustomerSalesRequest $request): JsonResponse
    {
        return $this->success($this->customerSalesService->achievement($request->validated()));
    }

    public function export(IndexCustomerSalesRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new CustomerSalesExport($this->customerSalesService->exportRows($filters, $format));
        $fileName = $this->customerSalesService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
