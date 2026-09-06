<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexReceiptStockRequest;
use App\Http\Requests\StoreReceiptStockRequest;
use App\Http\Requests\UpdateReceiptStockRequest;
use App\Http\Resources\ReceiptStockResource;
use App\Models\ReceiptStock;
use App\Services\ReceiptStockService;
use Illuminate\Http\JsonResponse;

class ReceiptStockController extends Controller
{
    use ApiResponse;

    public function __construct(protected ReceiptStockService $receiptStockService) {}

    public function index(IndexReceiptStockRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(ReceiptStockResource::collection(
            $this->receiptStockService->list($filters, $perPage)
        ));
    }

    public function store(StoreReceiptStockRequest $request): JsonResponse
    {
        $receiptStock = $this->receiptStockService->create($request->validated());

        return $this->success(new ReceiptStockResource($receiptStock), 'Receipt Stock created.', 201);
    }

    public function show(ReceiptStock $receiptStock): JsonResponse
    {
        return $this->success(new ReceiptStockResource($receiptStock->load(['warehouse', 'items'])));
    }

    public function update(UpdateReceiptStockRequest $request, ReceiptStock $receiptStock): JsonResponse
    {
        $receiptStock = $this->receiptStockService->update($receiptStock, $request->validated());

        return $this->success(new ReceiptStockResource($receiptStock), 'Receipt Stock updated.');
    }

    public function destroy(ReceiptStock $receiptStock): JsonResponse
    {
        $this->receiptStockService->delete($receiptStock);

        return $this->success(null, 'Receipt Stock deleted.');
    }

    public function submit(ReceiptStock $receiptStock): JsonResponse
    {
        $receiptStock = $this->receiptStockService->submit($receiptStock);

        return $this->success(new ReceiptStockResource($receiptStock), 'Receipt Stock submitted.');
    }

    public function cancel(ReceiptStock $receiptStock): JsonResponse
    {
        $receiptStock = $this->receiptStockService->cancel($receiptStock);

        return $this->success(new ReceiptStockResource($receiptStock), 'Receipt Stock cancelled.');
    }
}
