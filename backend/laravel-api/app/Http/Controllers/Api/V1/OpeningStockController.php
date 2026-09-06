<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexOpeningStockRequest;
use App\Http\Requests\StoreOpeningStockRequest;
use App\Http\Requests\UpdateOpeningStockRequest;
use App\Http\Resources\OpeningStockResource;
use App\Models\OpeningStock;
use App\Services\OpeningStockService;
use Illuminate\Http\JsonResponse;

class OpeningStockController extends Controller
{
    use ApiResponse;

    public function __construct(protected OpeningStockService $openingStockService) {}

    public function index(IndexOpeningStockRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(OpeningStockResource::collection(
            $this->openingStockService->list($filters, $perPage)
        ));
    }

    public function store(StoreOpeningStockRequest $request): JsonResponse
    {
        $openingStock = $this->openingStockService->create($request->validated());

        return $this->success(new OpeningStockResource($openingStock), 'Opening Stock created.', 201);
    }

    public function show(OpeningStock $openingStock): JsonResponse
    {
        return $this->success(new OpeningStockResource($openingStock->load(['warehouse', 'items'])));
    }

    public function update(UpdateOpeningStockRequest $request, OpeningStock $openingStock): JsonResponse
    {
        $openingStock = $this->openingStockService->update($openingStock, $request->validated());

        return $this->success(new OpeningStockResource($openingStock), 'Opening Stock updated.');
    }

    public function destroy(OpeningStock $openingStock): JsonResponse
    {
        $this->openingStockService->delete($openingStock);

        return $this->success(null, 'Opening Stock deleted.');
    }

    public function submit(OpeningStock $openingStock): JsonResponse
    {
        $openingStock = $this->openingStockService->submit($openingStock);

        return $this->success(new OpeningStockResource($openingStock), 'Opening Stock submitted.');
    }

    public function cancel(OpeningStock $openingStock): JsonResponse
    {
        $openingStock = $this->openingStockService->cancel($openingStock);

        return $this->success(new OpeningStockResource($openingStock), 'Opening Stock cancelled.');
    }

    public function submitBatch(string $importBatchId): JsonResponse
    {
        $documents = $this->openingStockService->submitBatch($importBatchId);

        return $this->success(OpeningStockResource::collection($documents), "Submitted {$documents->count()} document(s).");
    }

    public function cancelBatch(string $importBatchId): JsonResponse
    {
        $documents = $this->openingStockService->cancelBatch($importBatchId);

        return $this->success(OpeningStockResource::collection($documents), "Cancelled {$documents->count()} document(s).");
    }
}
