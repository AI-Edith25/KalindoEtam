<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\GoodsReceiptExport;
use App\Exports\GoodsReceiptListingDetailExport;
use App\Exports\GoodsReceiptListingSummaryExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportGoodsReceiptListingRequest;
use App\Http\Requests\IndexGoodsReceiptRequest;
use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Http\Requests\UpdateGoodsReceiptRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Services\GoodsReceiptReportService;
use App\Services\GoodsReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GoodsReceiptController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected GoodsReceiptService $goodsReceiptService,
        protected GoodsReceiptReportService $goodsReceiptReportService,
    ) {}

    public function index(IndexGoodsReceiptRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(GoodsReceiptResource::collection(
            $this->goodsReceiptService->list($filters, $perPage)
        ));
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $goodsReceipt = $this->goodsReceiptService->create($request->validated());

        return $this->success(new GoodsReceiptResource($goodsReceipt), 'Goods Receipt created.', 201);
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        return $this->success(new GoodsReceiptResource($goodsReceipt->load(['supplier', 'warehouse', 'purchaseOrder', 'items'])));
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $goodsReceipt = $this->goodsReceiptService->update($goodsReceipt, $request->validated());

        return $this->success(new GoodsReceiptResource($goodsReceipt), 'Goods Receipt updated.');
    }

    public function destroy(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $this->goodsReceiptService->delete($goodsReceipt);

        return $this->success(null, 'Goods Receipt deleted.');
    }

    /**
     * No cancel() action here, deliberately — see GoodsReceipt::cancel().
     */
    public function submit(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);

        return $this->success(new GoodsReceiptResource($goodsReceipt), 'Goods Receipt submitted.');
    }

    /** Same filters as index(), unpaginated — XLSX/CSV export. */
    public function export(IndexGoodsReceiptRequest $request): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->goodsReceiptService->listAll($filters);

        return Excel::download(new GoodsReceiptExport($rows), "goods-receipts.{$format}");
    }

    /**
     * Purchase > Goods Receipts' "Export XLSX/CSV" — Detail/Summary, same filters as index(),
     * unpaginated. Distinct from export() above (a different, already-shipped plain export
     * feeding the Reports module's Goods Receipt Report page) — do not confuse the two.
     */
    public function exportListing(ExportGoodsReceiptListingRequest $request): BinaryFileResponse
    {
        $data = $request->validated();
        $format = $data['format'] ?? 'xlsx';
        $receipts = $this->goodsReceiptReportService->rows($data);
        $moneyColumns = ['F' => '#,##0.00', 'G' => '#,##0.00', 'H' => '#,##0.00', 'I' => '#,##0.00'];

        if ($data['mode'] === 'detail') {
            $wrapped = $this->goodsReceiptReportService->wrapReport(
                title: 'GOODS RECEIVE NOTE LISTING - DETAIL',
                dateRangeSuffix: '',
                headingRows: [$this->goodsReceiptReportService->detailHeadings()],
                bodyRows: $this->goodsReceiptReportService->detailRows($receipts),
                filters: $data,
                documents: $receipts,
                dateField: 'receipt_date',
                lastColumn: 'R',
                timestampColumn: 'R',
                numberFormatColumns: [...$moneyColumns, 'J' => '#,##0.00'],
            );
            $export = new GoodsReceiptListingDetailExport($wrapped['rows'], $wrapped);
            $filename = "GoodsReceiveNotesListing_Detail.{$format}";
        } else {
            $wrapped = $this->goodsReceiptReportService->wrapReport(
                title: 'GOODS RECEIVE NOTE LISTING - SUMMARY',
                dateRangeSuffix: '',
                headingRows: [$this->goodsReceiptReportService->summaryHeadings()],
                bodyRows: $this->goodsReceiptReportService->summaryRows($receipts),
                filters: $data,
                documents: $receipts,
                dateField: 'receipt_date',
                lastColumn: 'K',
                timestampColumn: 'I',
                numberFormatColumns: $moneyColumns,
            );
            $export = new GoodsReceiptListingSummaryExport($wrapped['rows'], $wrapped);
            $filename = "GoodsReceiveNotesListing_Summary.{$format}";
        }

        return Excel::download($export, $filename);
    }
}
