<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\MarginExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexMarginRequest;
use App\Http\Resources\MarginRowResource;
use App\Services\MarginService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Sales Report's Margin tab — read-only, Profit = Penjualan (excl. tax) - HPP, grouped by item/customer/invoice. */
class MarginController extends Controller
{
    use ApiResponse;

    public function __construct(protected MarginService $marginService) {}

    public function index(IndexMarginRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(
            MarginRowResource::collection($this->marginService->list($filters, $perPage)),
            extraMeta: ['kpis' => $this->marginService->kpis($filters)],
        );
    }

    public function export(IndexMarginRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new MarginExport($this->marginService->exportRows($filters, $format));
        $fileName = $this->marginService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
