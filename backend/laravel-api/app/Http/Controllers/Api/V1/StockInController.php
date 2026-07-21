<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStockInRequest;
use App\Http\Resources\StockInResource;
use App\Services\StockInService;
use Illuminate\Http\JsonResponse;

class StockInController extends Controller
{
    use ApiResponse;

    public function __construct(protected StockInService $stockInService) {}

    public function store(StoreStockInRequest $request): JsonResponse
    {
        $records = $this->stockInService->store($request->validated());

        return $this->success(StockInResource::collection($records), 'Stock in recorded.', 201);
    }
}
