<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use ApiResponse;

    public function __construct(protected SupplierService $supplierService) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success(SupplierResource::collection($this->supplierService->list(
            (int) ($request->query('per_page') ?? 200),
            $request->query('search'),
        )));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = $this->supplierService->create($request->validated());

        return $this->success(new SupplierResource($supplier), 'Supplier created.', 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return $this->success(new SupplierResource($supplier));
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier = $this->supplierService->update($supplier, $request->validated());

        return $this->success(new SupplierResource($supplier), 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->supplierService->delete($supplier);

        return $this->success(null, 'Supplier deleted.');
    }
}
