<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\InventoryValuationExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInventoryValuationRequest;
use App\Http\Resources\InventoryValuationResource;
use App\Services\InventoryValuationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryValuationController extends Controller
{
    use ApiResponse;

    public function __construct(protected InventoryValuationService $inventoryValuationService) {}

    public function index(IndexInventoryValuationRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $page = (int) $request->query('page', 1);
        $perPage = $filters['per_page'] ?? 15;

        $rows = $this->inventoryValuationService->report($filters, $page, $perPage);
        $summary = $this->inventoryValuationService->summary($filters);

        return $this->success(InventoryValuationResource::collection($rows), '', 200, ['summary' => $summary]);
    }

    public function export(IndexInventoryValuationRequest $request): BinaryFileResponse
    {
        $validated = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]]);
        $format = $validated['format'] ?? 'xlsx';

        $filters = $request->validated();
        unset($filters['per_page'], $filters['format']);

        $rows = $this->inventoryValuationService->exportRows($filters);

        return Excel::download(new InventoryValuationExport($rows), "InventoryValuation.{$format}");
    }
}
