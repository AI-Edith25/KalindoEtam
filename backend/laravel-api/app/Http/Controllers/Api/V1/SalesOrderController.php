<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSalesOrderRequest;
use App\Http\Requests\StoreSalesOrderRequest;
use App\Http\Requests\UpdateSalesOrderRequest;
use App\Http\Resources\ApprovalFlowResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\SalesOrder;
use App\Services\ApprovalService;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;

class SalesOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SalesOrderService $salesOrderService,
        protected ApprovalService $approvalService,
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
        return $this->success(new SalesOrderResource($salesOrder->load(['customer', 'items.item', 'approvalFlows.approver'])));
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

    public function submit(SalesOrder $salesOrder): JsonResponse
    {
        $salesOrder = $this->salesOrderService->submit($salesOrder);

        return $this->success(new SalesOrderResource($salesOrder), 'Sales Order submitted.');
    }

    public function cancel(SalesOrder $salesOrder): JsonResponse
    {
        $salesOrder = $this->salesOrderService->cancel($salesOrder);

        return $this->success(new SalesOrderResource($salesOrder), 'Sales Order cancelled.');
    }

    public function requestApproval(SalesOrder $salesOrder): JsonResponse
    {
        $flow = $this->approvalService->requestApproval($salesOrder);

        return $this->success(new ApprovalFlowResource($flow), 'Approval requested.', 201);
    }
}
