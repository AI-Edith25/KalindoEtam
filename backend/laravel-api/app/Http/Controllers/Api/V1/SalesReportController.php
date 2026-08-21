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
                [$this->salesReportService->detailHeadings()],
                $this->salesReportService->detailRows($invoices),
                $data,
                $invoices,
                lastColumn: 'Q',
                dateColumn: 'A',
                withDataBorders: true,
                dateFormat: 'dd-mm-yyyy',
                // Money/qty columns right-aligned; everything else (including DATE and T.CODE,
                // which read as text) stays left — see detailRows()'s own docblock for column letters.
                rightAlignColumns: ['H', 'I', 'J', 'K', 'L', 'N'],
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
