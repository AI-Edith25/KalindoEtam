<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesPersonRequest;
use App\Http\Requests\UpdateSalesPersonRequest;
use App\Http\Resources\SalesPersonResource;
use App\Models\SalesPerson;
use App\Services\SalesPersonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesPersonController extends Controller
{
    use ApiResponse;

    public function __construct(protected SalesPersonService $salesPersonService) {}

    /** Preview of the code the New Sales Person form will get on save — see SalesPersonService::peekNextCode(). */
    public function nextCode(): JsonResponse
    {
        return $this->success(['code' => $this->salesPersonService->peekNextCode()]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->success(SalesPersonResource::collection($this->salesPersonService->list((int) ($request->query('per_page') ?? 15))));
    }

    public function store(StoreSalesPersonRequest $request): JsonResponse
    {
        $salesPerson = $this->salesPersonService->create($request->validated());

        return $this->success(new SalesPersonResource($salesPerson), 'Sales person created.', 201);
    }

    public function show(SalesPerson $salesPerson): JsonResponse
    {
        return $this->success(new SalesPersonResource($salesPerson->load('warehouse')));
    }

    public function update(UpdateSalesPersonRequest $request, SalesPerson $salesPerson): JsonResponse
    {
        $salesPerson = $this->salesPersonService->update($salesPerson, $request->validated());

        return $this->success(new SalesPersonResource($salesPerson), 'Sales person updated.');
    }

    public function destroy(SalesPerson $salesPerson): JsonResponse
    {
        $this->salesPersonService->delete($salesPerson);

        return $this->success(null, 'Sales person deleted.');
    }
}
