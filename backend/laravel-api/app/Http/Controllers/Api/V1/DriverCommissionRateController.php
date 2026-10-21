<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDriverCommissionRateRequest;
use App\Http\Resources\DriverCommissionRateResource;
use App\Models\DriverCommissionRate;
use App\Services\DriverCommissionRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverCommissionRateController extends Controller
{
    use ApiResponse;

    public function __construct(protected DriverCommissionRateService $driverCommissionRateService) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['fleet_id' => ['required', 'uuid']]);

        return $this->success(DriverCommissionRateResource::collection($this->driverCommissionRateService->listForFleet($request->query('fleet_id'))));
    }

    public function store(StoreDriverCommissionRateRequest $request): JsonResponse
    {
        $rate = $this->driverCommissionRateService->create($request->validated());

        return $this->success(new DriverCommissionRateResource($rate), 'Commission rate added.', 201);
    }

    public function destroy(DriverCommissionRate $driverCommissionRate): JsonResponse
    {
        $this->driverCommissionRateService->delete($driverCommissionRate);

        return $this->success(null, 'Commission rate deleted.');
    }
}
