<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexStockTransferRequest;
use App\Http\Requests\StoreStockTransferRequest;
use App\Http\Requests\UpdateStockTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\StockTransferService;
use Illuminate\Http\JsonResponse;

class StockTransferController extends Controller
{
    use ApiResponse;

    public function __construct(protected StockTransferService $stockTransferService) {}

    public function index(IndexStockTransferRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(StockTransferResource::collection(
            $this->stockTransferService->list($filters, $perPage)
        ));
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $stockTransfer = $this->stockTransferService->create($request->validated());

        return $this->success(new StockTransferResource($stockTransfer), 'Stock Transfer created.', 201);
    }

    public function show(StockTransfer $stockTransfer): JsonResponse
    {
        return $this->success(new StockTransferResource($stockTransfer->load(['sourceWarehouse', 'destinationWarehouse', 'items'])));
    }

    public function update(UpdateStockTransferRequest $request, StockTransfer $stockTransfer): JsonResponse
    {
        $stockTransfer = $this->stockTransferService->update($stockTransfer, $request->validated());

        return $this->success(new StockTransferResource($stockTransfer), 'Stock Transfer updated.');
    }

    public function destroy(StockTransfer $stockTransfer): JsonResponse
    {
        $this->stockTransferService->delete($stockTransfer);

        return $this->success(null, 'Stock Transfer deleted.');
    }

    /**
     * No cancel() action here, deliberately — see StockTransfer::cancel().
     */
    public function submit(StockTransfer $stockTransfer): JsonResponse
    {
        $stockTransfer = $this->stockTransferService->submit($stockTransfer);

        return $this->success(new StockTransferResource($stockTransfer), 'Stock Transfer submitted.');
    }
}
