<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePriceZoneRequest;
use App\Http\Requests\UpdatePriceZoneRequest;
use App\Http\Resources\PriceZoneResource;
use App\Models\PriceZone;
use App\Services\PriceZoneService;
use Illuminate\Http\JsonResponse;

class PriceZoneController extends Controller
{
    use ApiResponse;

    public function __construct(protected PriceZoneService $priceZoneService) {}

    public function index(): JsonResponse
    {
        return $this->success(PriceZoneResource::collection($this->priceZoneService->list()));
    }

    public function store(StorePriceZoneRequest $request): JsonResponse
    {
        $priceZone = $this->priceZoneService->create($request->validated());

        return $this->success(new PriceZoneResource($priceZone), 'Price zone created.', 201);
    }

    public function show(PriceZone $priceZone): JsonResponse
    {
        return $this->success(new PriceZoneResource($priceZone));
    }

    public function update(UpdatePriceZoneRequest $request, PriceZone $priceZone): JsonResponse
    {
        $priceZone = $this->priceZoneService->update($priceZone, $request->validated());

        return $this->success(new PriceZoneResource($priceZone), 'Price zone updated.');
    }

    public function destroy(PriceZone $priceZone): JsonResponse
    {
        $this->priceZoneService->delete($priceZone);

        return $this->success(null, 'Price zone deleted.');
    }
}
