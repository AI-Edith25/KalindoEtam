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

        $export = $data['mode'] === 'detail'
            ? new SalesReportDetailExport($this->salesReportService->detailRows($invoices))
            : new SalesReportSummaryExport($this->salesReportService->summaryRows($invoices));

        return Excel::download($export, "sales-report-{$data['mode']}.{$format}");
    }
}
