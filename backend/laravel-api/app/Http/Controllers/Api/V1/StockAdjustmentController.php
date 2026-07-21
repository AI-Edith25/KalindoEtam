<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexStockAdjustmentRequest;
use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Http\Requests\UpdateStockAdjustmentRequest;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;

class StockAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(protected StockAdjustmentService $stockAdjustmentService) {}

    public function index(IndexStockAdjustmentRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(StockAdjustmentResource::collection(
            $this->stockAdjustmentService->list($filters, $perPage)
        ));
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $stockAdjustment = $this->stockAdjustmentService->create($request->validated());

        return $this->success(new StockAdjustmentResource($stockAdjustment), 'Stock Adjustment created.', 201);
    }

    public function show(StockAdjustment $stockAdjustment): JsonResponse
    {
        return $this->success(new StockAdjustmentResource($stockAdjustment->load(['warehouse', 'items'])));
    }

    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        $stockAdjustment = $this->stockAdjustmentService->update($stockAdjustment, $request->validated());

        return $this->success(new StockAdjustmentResource($stockAdjustment), 'Stock Adjustment updated.');
    }

    public function destroy(StockAdjustment $stockAdjustment): JsonResponse
    {
        $this->stockAdjustmentService->delete($stockAdjustment);

        return $this->success(null, 'Stock Adjustment deleted.');
    }

    /**
     * No cancel() action here, deliberately — see StockAdjustment::cancel().
     */
    public function submit(StockAdjustment $stockAdjustment): JsonResponse
    {
        $stockAdjustment = $this->stockAdjustmentService->submit($stockAdjustment);

        return $this->success(new StockAdjustmentResource($stockAdjustment), 'Stock Adjustment submitted.');
    }
}
