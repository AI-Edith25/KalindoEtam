<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\OpenOrdersExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexOpenOrdersRequest;
use App\Http\Resources\OpenOrdersRowResource;
use App\Services\OpenOrdersService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Sales Report's Open Orders tab — read-only, one row per Sales Order line still outstanding. */
class OpenOrdersController extends Controller
{
    use ApiResponse;

    public function __construct(protected OpenOrdersService $openOrdersService) {}

    public function index(IndexOpenOrdersRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 25;

        return $this->success(
            OpenOrdersRowResource::collection($this->openOrdersService->list($filters, $perPage)),
            extraMeta: ['kpis' => $this->openOrdersService->kpis($filters)],
        );
    }

    public function export(IndexOpenOrdersRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';

        $export = new OpenOrdersExport($this->openOrdersService->exportRows($filters, $format));
        $fileName = $this->openOrdersService->fileName($filters, $format);

        return Excel::download($export, $fileName);
    }
}
