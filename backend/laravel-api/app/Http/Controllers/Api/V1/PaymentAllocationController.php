<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentAllocationRequest;
use App\Http\Resources\PaymentAllocationResource;
use App\Models\PaymentAllocation;
use App\Models\ReceiptEntry;
use App\Services\PaymentAllocationService;
use Illuminate\Http\JsonResponse;

class PaymentAllocationController extends Controller
{
    use ApiResponse;

    public function __construct(protected PaymentAllocationService $paymentAllocationService) {}

    public function store(StorePaymentAllocationRequest $request, ReceiptEntry $receiptEntry): JsonResponse
    {
        $allocations = $this->paymentAllocationService->allocateBatch($receiptEntry, $request->validated('allocations'));

        return $this->success(PaymentAllocationResource::collection($allocations), 'Payment allocated.', 201);
    }

    public function reverse(PaymentAllocation $paymentAllocation): JsonResponse
    {
        $paymentAllocation = $this->paymentAllocationService->reverse($paymentAllocation);

        return $this->success(new PaymentAllocationResource($paymentAllocation), 'Allocation reversed.');
    }
}
