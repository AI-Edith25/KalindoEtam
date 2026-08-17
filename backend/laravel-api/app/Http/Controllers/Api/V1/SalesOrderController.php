<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSalesOrderRequest;
use App\Http\Requests\StoreSalesOrderRequest;
use App\Http\Requests\UpdateSalesOrderRequest;
use App\Http\Resources\SalesOrderResource;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SalesOrderService $salesOrderService,
    ) {}

    public function index(IndexSalesOrderRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(SalesOrderResource::collection(
            $this->salesOrderService->list($filters, $perPage)
        ));
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $salesOrder = $this->salesOrderService->create($request->validated());

        return $this->success(new SalesOrderResource($salesOrder), 'Sales Order created.', 201);
    }

    public function show(SalesOrder $salesOrder): JsonResponse
    {
        return $this->success(new SalesOrderResource($salesOrder->load(['customer', 'salesPerson', 'branch', 'termsOfPayment', 'tax', 'items.item.uom', 'approvalFlows.approver'])));
    }

    public function update(UpdateSalesOrderRequest $request, SalesOrder $salesOrder): JsonResponse
    {
        $salesOrder = $this->salesOrderService->update($salesOrder, $request->validated());

        return $this->success(new SalesOrderResource($salesOrder), 'Sales Order updated.');
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        $this->salesOrderService->delete($salesOrder);

        return $this->success(null, 'Sales Order deleted.');
    }

    public function approve(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $salesOrder = $this->salesOrderService->approve(
            $salesOrder,
            $request->boolean('override_credit_block'),
            $request->input('override_reason'),
        );

        return $this->success(new SalesOrderResource($salesOrder), 'Sales Order approved.');
    }

    public function cancel(SalesOrder $salesOrder): JsonResponse
    {
        $salesOrder = $this->salesOrderService->cancel($salesOrder);

        return $this->success(new SalesOrderResource($salesOrder), 'Sales Order cancelled.');
    }
}
