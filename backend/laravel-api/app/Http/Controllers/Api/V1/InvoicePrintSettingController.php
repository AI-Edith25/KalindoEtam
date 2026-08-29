<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateInvoicePrintSettingRequest;
use App\Http\Resources\InvoicePrintSettingResource;
use App\Services\InvoicePrintSettingService;
use Illuminate\Http\JsonResponse;

class InvoicePrintSettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected InvoicePrintSettingService $invoicePrintSettingService,
    ) {}

    public function show(): JsonResponse
    {
        return $this->success(new InvoicePrintSettingResource($this->invoicePrintSettingService->current()));
    }

    public function update(UpdateInvoicePrintSettingRequest $request): JsonResponse
    {
        $setting = $this->invoicePrintSettingService->update($request->validated());

        return $this->success(new InvoicePrintSettingResource($setting), 'Invoice print settings updated.');
    }
}
