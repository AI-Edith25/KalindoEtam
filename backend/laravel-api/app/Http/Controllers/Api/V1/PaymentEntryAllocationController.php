<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentEntryAllocationRequest;
use App\Http\Resources\PaymentEntryAllocationResource;
use App\Models\PaymentEntry;
use App\Models\PaymentEntryAllocation;
use App\Services\PaymentEntryAllocationService;
use Illuminate\Http\JsonResponse;

class PaymentEntryAllocationController extends Controller
{
    use ApiResponse;

    public function __construct(protected PaymentEntryAllocationService $paymentEntryAllocationService) {}

    public function store(StorePaymentEntryAllocationRequest $request, PaymentEntry $paymentEntry): JsonResponse
    {
        $allocations = $this->paymentEntryAllocationService->allocateBatch($paymentEntry, $request->validated('allocations'));

        return $this->success(PaymentEntryAllocationResource::collection($allocations), 'Payment allocated.', 201);
    }

    public function reverse(PaymentEntryAllocation $paymentEntryAllocation): JsonResponse
    {
        $paymentEntryAllocation = $this->paymentEntryAllocationService->reverse($paymentEntryAllocation);

        return $this->success(new PaymentEntryAllocationResource($paymentEntryAllocation), 'Allocation reversed.');
    }
}
