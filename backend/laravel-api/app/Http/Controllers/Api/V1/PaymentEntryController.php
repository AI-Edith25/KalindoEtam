<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexPaymentEntryRequest;
use App\Http\Requests\StorePaymentEntryRequest;
use App\Http\Requests\UpdatePaymentEntryRequest;
use App\Http\Resources\PaymentEntryResource;
use App\Models\PaymentEntry;
use App\Services\PaymentEntryService;
use Illuminate\Http\JsonResponse;

class PaymentEntryController extends Controller
{
    use ApiResponse;

    public function __construct(protected PaymentEntryService $paymentEntryService) {}

    public function index(IndexPaymentEntryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(PaymentEntryResource::collection($this->paymentEntryService->list($filters, $perPage)));
    }

    public function store(StorePaymentEntryRequest $request): JsonResponse
    {
        $paymentEntry = $this->paymentEntryService->create($request->validated());

        return $this->success(new PaymentEntryResource($paymentEntry), 'Payment Entry created.', 201);
    }

    public function show(PaymentEntry $paymentEntry): JsonResponse
    {
        return $this->success(new PaymentEntryResource($paymentEntry->load(['supplier', 'items.accountsPayable'])));
    }

    public function update(UpdatePaymentEntryRequest $request, PaymentEntry $paymentEntry): JsonResponse
    {
        $paymentEntry = $this->paymentEntryService->update($paymentEntry, $request->validated());

        return $this->success(new PaymentEntryResource($paymentEntry), 'Payment Entry updated.');
    }

    public function destroy(PaymentEntry $paymentEntry): JsonResponse
    {
        $this->paymentEntryService->delete($paymentEntry);

        return $this->success(null, 'Payment Entry deleted.');
    }

    /**
     * No cancel() action here, deliberately — see PaymentEntry::cancel().
     */
    public function submit(PaymentEntry $paymentEntry): JsonResponse
    {
        $paymentEntry = $this->paymentEntryService->submit($paymentEntry);

        return $this->success(new PaymentEntryResource($paymentEntry), 'Payment Entry submitted.');
    }
}
