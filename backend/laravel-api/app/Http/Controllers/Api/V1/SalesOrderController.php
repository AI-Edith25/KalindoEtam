<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\ExportsSalesList;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSalesOrderRequest;
use App\Http\Requests\StoreSalesOrderRequest;
use App\Http\Requests\UpdateSalesOrderRequest;
use App\Http\Resources\SalesOrderResource;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesOrderController extends Controller
{
    use ApiResponse, ExportsSalesList;

    /** Ordered [columnKey => label] — every column bulk-export can produce, in display order. Mirrors SalesOrderListPage.tsx's own table columns. */
    protected const COLUMNS = [
        'order_date' => 'Date',
        'document_number' => 'Document',
        'customer_name' => 'Customer Name',
        'total_amount' => 'Amount',
        'status' => 'Status',
    ];

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

    /**
     * Bulk export — same filters as index() (via IndexSalesOrderRequest), plus `ids[]` (checked
     * rows override the filter entirely, same contract as InvoiceRepository::searchAll()),
     * `columns[]` (subset of self::COLUMNS to include, default all), and `format` (xlsx/csv).
     */
    public function export(IndexSalesOrderRequest $request): BinaryFileResponse
    {
        $extra = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => [Rule::in(array_keys(self::COLUMNS))],
        ]);

        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->salesOrderService->listAll($filters, $extra['ids'] ?? null);

        return $this->exportSalesList(
            $rows,
            self::COLUMNS,
            $extra['columns'] ?? null,
            fn (SalesOrder $row, string $key) => match ($key) {
                'order_date' => $row->order_date?->format('Y-m-d'),
                'document_number' => $row->document_number,
                'customer_name' => $row->customer?->customer_name,
                'total_amount' => (float) $row->total_amount,
                'status' => ucfirst($row->status?->value ?? ''),
                default => null,
            },
            'sales_orders',
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $extra['format'] ?? 'xlsx',
        );
    }
}
