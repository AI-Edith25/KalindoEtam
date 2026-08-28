<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesTargetRequest;
use App\Http\Requests\UpdateSalesTargetRequest;
use App\Http\Resources\SalesTargetResource;
use App\Models\SalesTarget;
use App\Services\SalesTargetService;
use Illuminate\Http\JsonResponse;

class SalesTargetController extends Controller
{
    use ApiResponse;

    public function __construct(protected SalesTargetService $salesTargetService) {}

    public function index(): JsonResponse
    {
        return $this->success(SalesTargetResource::collection($this->salesTargetService->list()));
    }

    public function store(StoreSalesTargetRequest $request): JsonResponse
    {
        $salesTarget = $this->salesTargetService->create($request->validated());

        return $this->success(new SalesTargetResource($salesTarget), 'Sales target created.', 201);
    }

    public function show(SalesTarget $salesTarget): JsonResponse
    {
        return $this->success(new SalesTargetResource($salesTarget->load(['salesPerson', 'branch'])));
    }

    public function update(UpdateSalesTargetRequest $request, SalesTarget $salesTarget): JsonResponse
    {
        $salesTarget = $this->salesTargetService->update($salesTarget, $request->validated());

        return $this->success(new SalesTargetResource($salesTarget), 'Sales target updated.');
    }

    public function destroy(SalesTarget $salesTarget): JsonResponse
    {
        $this->salesTargetService->delete($salesTarget);

        return $this->success(null, 'Sales target deleted.');
    }
}
