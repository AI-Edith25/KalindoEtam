<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDeliveryRequest;
use App\Http\Requests\StoreDeliveryRequest;
use App\Http\Requests\UpdateDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;

class DeliveryController extends Controller
{
    use ApiResponse;

    public function __construct(protected DeliveryService $deliveryService) {}

    public function index(IndexDeliveryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(DeliveryResource::collection(
            $this->deliveryService->list($filters, $perPage)
        ));
    }

    public function store(StoreDeliveryRequest $request): JsonResponse
    {
        $delivery = $this->deliveryService->create($request->validated());

        return $this->success(new DeliveryResource($delivery), 'Delivery created.', 201);
    }

    public function show(Delivery $delivery): JsonResponse
    {
        return $this->success(new DeliveryResource($delivery->load(['customer', 'warehouse', 'salesOrder', 'items', 'invoice'])));
    }

    public function update(UpdateDeliveryRequest $request, Delivery $delivery): JsonResponse
    {
        $delivery = $this->deliveryService->update($delivery, $request->validated());

        return $this->success(new DeliveryResource($delivery), 'Delivery updated.');
    }

    public function destroy(Delivery $delivery): JsonResponse
    {
        $this->deliveryService->delete($delivery);

        return $this->success(null, 'Delivery deleted.');
    }

    /**
     * No cancel() action here, deliberately — see Delivery::cancel().
     */
    public function submit(Delivery $delivery): JsonResponse
    {
        $delivery = $this->deliveryService->submit($delivery);

        return $this->success(new DeliveryResource($delivery), 'Delivery submitted.');
    }
}
