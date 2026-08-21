<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SalesReportDetailExport;
use App\Exports\SalesReportSummaryExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExportSalesReportRequest;
use App\Services\SalesReportService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Sales → Invoices' "Laporan Penjualan" export — read-only, no index/show, just the one download action. */
class SalesReportController extends Controller
{
    public function __construct(protected SalesReportService $salesReportService) {}

    public function export(ExportSalesReportRequest $request): BinaryFileResponse
    {
        $data = $request->validated();
        $format = $data['format'] ?? 'xlsx';
        $invoices = $this->salesReportService->rows($data, $data['ids'] ?? null);

        if ($data['mode'] === 'detail') {
            $wrapped = $this->salesReportService->wrapReport(
                'SALES INVOICE LISTING - DETAIL',
                $this->salesReportService->detailHeadings(),
                $this->salesReportService->detailRows($invoices),
                $data,
                $invoices,
                lastColumn: 'M',
            );
            $export = new SalesReportDetailExport($wrapped['rows'], $wrapped);
            $filename = "SalesInvoiceListing_Detail.{$format}";
        } else {
            $wrapped = $this->salesReportService->wrapReport(
                'SALES INVOICE LISTING - SUMMARY',
                [$this->salesReportService->summaryHeadings()],
                $this->salesReportService->summaryRows($invoices),
                $data,
                $invoices,
                lastColumn: 'L',
                dateColumn: 'A',
                withDataBorders: true,
            );
            $export = new SalesReportSummaryExport($wrapped['rows'], $wrapped);
            $filename = "SalesInvoiceListing_Summary.{$format}";
        }

        return Excel::download($export, $filename);
    }
}
