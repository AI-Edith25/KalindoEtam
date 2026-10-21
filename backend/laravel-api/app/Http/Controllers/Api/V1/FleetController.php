<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFleetRequest;
use App\Http\Requests\UpdateFleetRequest;
use App\Http\Resources\FleetResource;
use App\Models\Fleet;
use App\Services\FleetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FleetController extends Controller
{
    use ApiResponse;

    public function __construct(protected FleetService $fleetService) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('active_only')) {
            return $this->success(FleetResource::collection($this->fleetService->listActive()));
        }

        return $this->success(FleetResource::collection($this->fleetService->list((int) ($request->query('per_page') ?? 15))));
    }

    public function store(StoreFleetRequest $request): JsonResponse
    {
        $fleet = $this->fleetService->create($request->validated());

        return $this->success(new FleetResource($fleet), 'Fleet created.', 201);
    }

    public function show(Fleet $fleet): JsonResponse
    {
        return $this->success(new FleetResource($fleet));
    }

    public function update(UpdateFleetRequest $request, Fleet $fleet): JsonResponse
    {
        $fleet = $this->fleetService->update($fleet, $request->validated());

        return $this->success(new FleetResource($fleet), 'Fleet updated.');
    }

    public function destroy(Fleet $fleet): JsonResponse
    {
        $this->fleetService->delete($fleet);

        return $this->success(null, 'Fleet deleted.');
    }
}
