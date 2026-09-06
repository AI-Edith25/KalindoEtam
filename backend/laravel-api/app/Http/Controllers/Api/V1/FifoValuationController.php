<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\FifoValuationExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexFifoValuationRequest;
use App\Http\Resources\FifoValuationGroupResource;
use App\Services\FifoValuationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FifoValuationController extends Controller
{
    use ApiResponse;

    public function __construct(protected FifoValuationService $fifoValuationService) {}

    public function index(IndexFifoValuationRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        $groups = $this->fifoValuationService->grouped($filters, $perPage);
        $summary = $this->fifoValuationService->summary($filters);

        return $this->success(FifoValuationGroupResource::collection($groups), '', 200, ['summary' => $summary]);
    }

    public function export(IndexFifoValuationRequest $request): BinaryFileResponse
    {
        $validated = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]]);
        $format = $validated['format'] ?? 'xlsx';

        $filters = $request->validated();
        unset($filters['per_page'], $filters['format']);

        $rows = $this->fifoValuationService->exportRows($filters);

        return Excel::download(new FifoValuationExport($rows), "FifoLayers.{$format}");
    }
}
