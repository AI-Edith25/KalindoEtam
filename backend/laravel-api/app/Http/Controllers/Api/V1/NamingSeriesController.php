<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNamingSeriesRequest;
use App\Http\Requests\UpdateNamingSeriesRequest;
use App\Http\Resources\NamingSeriesResource;
use App\Models\NamingSeries;
use App\Services\NamingSeriesService;
use Illuminate\Http\JsonResponse;

class NamingSeriesController extends Controller
{
    use ApiResponse;

    public function __construct(protected NamingSeriesService $namingSeriesService) {}

    public function index(): JsonResponse
    {
        return $this->success(NamingSeriesResource::collection($this->namingSeriesService->list()));
    }

    public function store(StoreNamingSeriesRequest $request): JsonResponse
    {
        $namingSeries = $this->namingSeriesService->create($request->validated());

        return $this->success(new NamingSeriesResource($namingSeries), 'Naming series created.', 201);
    }

    public function show(NamingSeries $namingSeries): JsonResponse
    {
        return $this->success(new NamingSeriesResource($namingSeries));
    }

    public function update(UpdateNamingSeriesRequest $request, NamingSeries $namingSeries): JsonResponse
    {
        $namingSeries = $this->namingSeriesService->update($namingSeries, $request->validated());

        return $this->success(new NamingSeriesResource($namingSeries), 'Naming series updated.');
    }

    public function destroy(NamingSeries $namingSeries): JsonResponse
    {
        $this->namingSeriesService->delete($namingSeries);

        return $this->success(null, 'Naming series deleted.');
    }
}
