<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChartOfAccountRequest;
use App\Http\Requests\UpdateChartOfAccountRequest;
use App\Http\Resources\ChartOfAccountResource;
use App\Models\ChartOfAccount;
use App\Services\ChartOfAccountService;
use Illuminate\Http\JsonResponse;

class ChartOfAccountController extends Controller
{
    use ApiResponse;

    public function __construct(protected ChartOfAccountService $chartOfAccountService) {}

    public function index(): JsonResponse
    {
        return $this->success(ChartOfAccountResource::collection($this->chartOfAccountService->list()));
    }

    public function store(StoreChartOfAccountRequest $request): JsonResponse
    {
        $chartOfAccount = $this->chartOfAccountService->create($request->validated());

        return $this->success(new ChartOfAccountResource($chartOfAccount), 'Chart of Account created.', 201);
    }

    public function show(ChartOfAccount $chartOfAccount): JsonResponse
    {
        return $this->success(new ChartOfAccountResource($chartOfAccount));
    }

    public function update(UpdateChartOfAccountRequest $request, ChartOfAccount $chartOfAccount): JsonResponse
    {
        $chartOfAccount = $this->chartOfAccountService->update($chartOfAccount, $request->validated());

        return $this->success(new ChartOfAccountResource($chartOfAccount), 'Chart of Account updated.');
    }

    public function destroy(ChartOfAccount $chartOfAccount): JsonResponse
    {
        $this->chartOfAccountService->delete($chartOfAccount);

        return $this->success(null, 'Chart of Account deleted.');
    }
}
