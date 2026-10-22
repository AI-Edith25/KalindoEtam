<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\DriverCommissionReportExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDriverCommissionReportRequest;
use App\Http\Resources\DriverCommissionReportRowResource;
use App\Services\DriverCommissionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DriverCommissionReportController extends Controller
{
    use ApiResponse;

    public function __construct(protected DriverCommissionReportService $driverCommissionReportService) {}

    public function index(IndexDriverCommissionReportRequest $request): JsonResponse
    {
        $rows = $this->driverCommissionReportService->rows($request->validated());

        return $this->success([
            'rows' => DriverCommissionReportRowResource::collection($rows),
            'summary' => $this->driverCommissionReportService->summary($rows),
        ]);
    }

    public function export(IndexDriverCommissionReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]]);
        $format = $validated['format'] ?? 'xlsx';
        $rows = $this->driverCommissionReportService->rows($request->validated());

        return Excel::download(new DriverCommissionReportExport($rows, $format), 'KomisiSopir.'.$format);
    }
}
