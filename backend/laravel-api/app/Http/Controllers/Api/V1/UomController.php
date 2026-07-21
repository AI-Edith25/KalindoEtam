<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUomRequest;
use App\Http\Requests\UpdateUomRequest;
use App\Http\Resources\UomResource;
use App\Models\UnitOfMeasurement;
use App\Services\UomService;
use Illuminate\Http\JsonResponse;

class UomController extends Controller
{
    use ApiResponse;

    public function __construct(protected UomService $uomService) {}

    public function index(): JsonResponse
    {
        return $this->success(UomResource::collection($this->uomService->list()));
    }

    public function store(StoreUomRequest $request): JsonResponse
    {
        $uom = $this->uomService->create($request->validated());

        return $this->success(new UomResource($uom), 'UOM created.', 201);
    }

    public function show(UnitOfMeasurement $uom): JsonResponse
    {
        return $this->success(new UomResource($uom));
    }

    public function update(UpdateUomRequest $request, UnitOfMeasurement $uom): JsonResponse
    {
        $uom = $this->uomService->update($uom, $request->validated());

        return $this->success(new UomResource($uom), 'UOM updated.');
    }

    public function destroy(UnitOfMeasurement $uom): JsonResponse
    {
        $this->uomService->delete($uom);

        return $this->success(null, 'UOM deleted.');
    }
}
