<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePurchaseSettingRequest;
use App\Http\Resources\PurchaseSettingResource;
use App\Services\PurchaseSettingService;
use Illuminate\Http\JsonResponse;

class PurchaseSettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PurchaseSettingService $purchaseSettingService,
    ) {}

    public function show(): JsonResponse
    {
        return $this->success(new PurchaseSettingResource($this->purchaseSettingService->current()));
    }

    public function update(UpdatePurchaseSettingRequest $request): JsonResponse
    {
        $setting = $this->purchaseSettingService->update($request->validated());

        return $this->success(new PurchaseSettingResource($setting), 'Purchase settings updated.');
    }
}
