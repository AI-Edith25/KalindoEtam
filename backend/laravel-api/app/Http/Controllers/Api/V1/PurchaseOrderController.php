<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\PurchaseOrderListingDetailExport;
use App\Exports\PurchaseOrderListingSummaryExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportPurchaseOrderListingRequest;
use App\Http\Requests\IndexPurchaseOrderRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderRequest;
use App\Http\Resources\ApprovalFlowResource;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Services\ApprovalService;
use App\Services\PurchaseOrderReportService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PurchaseOrderService $purchaseOrderService,
        protected ApprovalService $approvalService,
        protected PurchaseOrderReportService $purchaseOrderReportService,
    ) {}

    public function index(IndexPurchaseOrderRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(PurchaseOrderResource::collection(
            $this->purchaseOrderService->list($filters, $perPage)
        ));
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $purchaseOrder = $this->purchaseOrderService->create($request->validated());

        return $this->success(new PurchaseOrderResource($purchaseOrder), 'Purchase Order created.', 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->success(new PurchaseOrderResource($purchaseOrder->load(['supplier', 'items.item', 'tax', 'approvalFlows.approver'])));
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder = $this->purchaseOrderService->update($purchaseOrder, $request->validated());

        return $this->success(new PurchaseOrderResource($purchaseOrder), 'Purchase Order updated.');
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->purchaseOrderService->delete($purchaseOrder);

        return $this->success(null, 'Purchase Order deleted.');
    }

    public function submit(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder);

        return $this->success(new PurchaseOrderResource($purchaseOrder), 'Purchase Order submitted.');
    }

    public function cancel(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder = $this->purchaseOrderService->cancel($purchaseOrder);

        return $this->success(new PurchaseOrderResource($purchaseOrder), 'Purchase Order cancelled.');
    }

    public function requestApproval(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $flow = $this->approvalService->requestApproval($purchaseOrder);

        return $this->success(new ApprovalFlowResource($flow), 'Approval requested.', 201);
    }

    /** Purchase > Purchase Orders' "Export XLSX/CSV" — Detail/Summary, same filters as index(), unpaginated. */
    public function export(ExportPurchaseOrderListingRequest $request): BinaryFileResponse
    {
        $data = $request->validated();
        $format = $data['format'] ?? 'xlsx';
        $orders = $this->purchaseOrderReportService->rows($data);
        $moneyColumns = ['F' => '#,##0.00', 'G' => '#,##0.00', 'H' => '#,##0.00', 'I' => '#,##0.00'];

        if ($data['mode'] === 'detail') {
            $wrapped = $this->purchaseOrderReportService->wrapReport(
                title: 'PURCHASE ORDER LISTING - DETAIL',
                dateRangeSuffix: ' - Base Currency',
                headingRows: [$this->purchaseOrderReportService->detailHeadings()],
                bodyRows: $this->purchaseOrderReportService->detailRows($orders),
                filters: $data,
                documents: $orders,
                dateField: 'order_date',
                lastColumn: 'L',
                timestampColumn: 'D',
                numberFormatColumns: [...$moneyColumns, 'K' => '#,##0.00'],
            );
            $export = new PurchaseOrderListingDetailExport($wrapped['rows'], $wrapped);
            $filename = "PurchaseOrderListing_Detail.{$format}";
        } else {
            $wrapped = $this->purchaseOrderReportService->wrapReport(
                title: 'PURCHASE ORDER LISTING - SUMMARY',
                dateRangeSuffix: ' - Base Currency',
                headingRows: [$this->purchaseOrderReportService->summaryHeadings()],
                bodyRows: $this->purchaseOrderReportService->summaryRows($orders),
                filters: $data,
                documents: $orders,
                dateField: 'order_date',
                lastColumn: 'J',
                timestampColumn: 'D',
                numberFormatColumns: $moneyColumns,
            );
            $export = new PurchaseOrderListingSummaryExport($wrapped['rows'], $wrapped);
            $filename = "PurchaseOrderListing_Summary.{$format}";
        }

        return Excel::download($export, $filename);
    }
}
