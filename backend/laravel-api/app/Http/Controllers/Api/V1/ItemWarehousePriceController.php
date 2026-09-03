<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUpdateItemWarehousePriceRequest;
use App\Http\Requests\ImportItemWarehousePricesRequest;
use App\Http\Resources\ItemWarehousePriceResource;
use App\Services\ItemWarehousePriceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemWarehousePriceController extends Controller
{
    use ApiResponse;

    public function __construct(protected ItemWarehousePriceService $itemWarehousePriceService) {}

    public function index(): JsonResponse
    {
        return $this->success(ItemWarehousePriceResource::collection($this->itemWarehousePriceService->list()));
    }

    public function bulkUpdate(BulkUpdateItemWarehousePriceRequest $request): JsonResponse
    {
        $results = $this->itemWarehousePriceService->bulkUpdate($request->validated('cells'));

        return $this->success($results, 'Prices saved.');
    }

    public function export(): StreamedResponse
    {
        return $this->itemWarehousePriceService->export();
    }

    public function importPreview(ImportItemWarehousePricesRequest $request): JsonResponse
    {
        $preview = $this->itemWarehousePriceService->importPreview($request->file('file'));

        return $this->success($preview);
    }

    public function importCommit(ImportItemWarehousePricesRequest $request): JsonResponse
    {
        $summary = $this->itemWarehousePriceService->importCommit($request->file('file'));

        return $this->success($summary, 'Import finished.');
    }
}
