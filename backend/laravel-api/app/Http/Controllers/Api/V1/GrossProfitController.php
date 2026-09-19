<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\GrossProfitExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexGrossProfitRequest;
use App\Http\Resources\GrossProfitRowResource;
use App\Services\GrossProfitService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Gross Profit report — read-only, Profit = Penjualan (excl. tax) - HPP, grouped by item/customer/invoice. Split out of Sales Report's old Margin tab (2026-09-19), same query/formula, own top-level route. */
class GrossProfitController extends Controller
{
    use ApiResponse;

    public function __construct(protected GrossProfitService $grossProfitService) {}

    public function index(IndexGrossProfitRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(
            GrossProfitRowResource::collection($this->grossProfitService->list($filters, $perPage)),
            extraMeta: ['kpis' => $this->grossProfitService->kpis($filters)],
        );
    }

    public function export(IndexGrossProfitRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new GrossProfitExport($this->grossProfitService->exportRows($filters, $format));
        $fileName = $this->grossProfitService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
