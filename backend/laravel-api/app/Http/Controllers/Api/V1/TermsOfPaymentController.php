<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTermsOfPaymentRequest;
use App\Http\Requests\UpdateTermsOfPaymentRequest;
use App\Http\Resources\TermsOfPaymentResource;
use App\Models\TermsOfPayment;
use App\Services\TermsOfPaymentService;
use Illuminate\Http\JsonResponse;

class TermsOfPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(protected TermsOfPaymentService $termsOfPaymentService) {}

    public function index(): JsonResponse
    {
        return $this->success(TermsOfPaymentResource::collection($this->termsOfPaymentService->list()));
    }

    public function store(StoreTermsOfPaymentRequest $request): JsonResponse
    {
        $termsOfPayment = $this->termsOfPaymentService->create($request->validated());

        return $this->success(new TermsOfPaymentResource($termsOfPayment), 'Terms of payment created.', 201);
    }

    public function show(TermsOfPayment $termsOfPayment): JsonResponse
    {
        return $this->success(new TermsOfPaymentResource($termsOfPayment));
    }

    public function update(UpdateTermsOfPaymentRequest $request, TermsOfPayment $termsOfPayment): JsonResponse
    {
        $termsOfPayment = $this->termsOfPaymentService->update($termsOfPayment, $request->validated());

        return $this->success(new TermsOfPaymentResource($termsOfPayment), 'Terms of payment updated.');
    }

    public function destroy(TermsOfPayment $termsOfPayment): JsonResponse
    {
        $this->termsOfPaymentService->delete($termsOfPayment);

        return $this->success(null, 'Terms of payment deleted.');
    }
}
