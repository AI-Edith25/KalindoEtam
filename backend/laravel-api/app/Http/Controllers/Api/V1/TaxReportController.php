<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\TaxInputExport;
use App\Exports\TaxOutputExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTaxReportRequest;
use App\Http\Resources\TaxReportRowResource;
use App\Services\TaxReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Read-only — every row here is synthesized from Sales/Purchase Invoice + Credit Note/Purchase Return, never a separate stored document. */
class TaxReportController extends Controller
{
    use ApiResponse;

    public function __construct(protected TaxReportService $taxReportService) {}

    public function outputTax(IndexTaxReportRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(TaxReportRowResource::collection($this->taxReportService->outputTax($filters, $perPage)));
    }

    public function inputTax(IndexTaxReportRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(TaxReportRowResource::collection($this->taxReportService->inputTax($filters, $perPage)));
    }

    /** Selisih (Kurang/Lebih Bayar) — both sub-tabs' current filters combined, same underlying queries as the two lists above. */
    public function summary(IndexTaxReportRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->success($this->taxReportService->summary($filters, $filters));
    }

    public function outputTaxExport(IndexTaxReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]]);
        $filters = $request->validated();
        unset($filters['per_page']);

        return Excel::download(new TaxOutputExport($this->taxReportService->outputTaxAll($filters)), 'PPNKeluaran.'.($validated['format'] ?? 'xlsx'));
    }

    public function inputTaxExport(IndexTaxReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]]);
        $filters = $request->validated();
        unset($filters['per_page']);

        return Excel::download(new TaxInputExport($this->taxReportService->inputTaxAll($filters)), 'PPNMasukan.'.($validated['format'] ?? 'xlsx'));
    }
}
