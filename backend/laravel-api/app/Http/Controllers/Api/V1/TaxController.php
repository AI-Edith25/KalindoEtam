<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaxRequest;
use App\Http\Requests\UpdateTaxRequest;
use App\Http\Resources\TaxResource;
use App\Models\Tax;
use App\Services\TaxService;
use Illuminate\Http\JsonResponse;

class TaxController extends Controller
{
    use ApiResponse;

    public function __construct(protected TaxService $taxService) {}

    public function index(): JsonResponse
    {
        return $this->success(TaxResource::collection($this->taxService->list()));
    }

    public function store(StoreTaxRequest $request): JsonResponse
    {
        $tax = $this->taxService->create($request->validated());

        return $this->success(new TaxResource($tax), 'Tax created.', 201);
    }

    public function show(Tax $tax): JsonResponse
    {
        return $this->success(new TaxResource($tax));
    }

    public function update(UpdateTaxRequest $request, Tax $tax): JsonResponse
    {
        $tax = $this->taxService->update($tax, $request->validated());

        return $this->success(new TaxResource($tax), 'Tax updated.');
    }

    public function destroy(Tax $tax): JsonResponse
    {
        $this->taxService->delete($tax);

        return $this->success(null, 'Tax deleted.');
    }
}
