<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\AccountsReceivableAgingDetailExport;
use App\Exports\AccountsReceivableAgingSummaryExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAccountsReceivableRequest;
use App\Http\Resources\AccountsReceivableResource;
use App\Models\AccountsReceivable;
use App\Services\AccountsReceivableAgingReportService;
use App\Services\AccountsReceivableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only — Accounts Receivable rows are only ever created as a side
 * effect of InvoiceService::submit(). No store/update/destroy.
 */
class AccountsReceivableController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountsReceivableAgingReportService $accountsReceivableAgingReportService,
    ) {}

    public function index(IndexAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(
            AccountsReceivableResource::collection($this->accountsReceivableService->list($filters, $perPage)),
            '',
            200,
            ['total_outstanding' => $this->accountsReceivableService->outstandingTotal($filters)]
        );
    }

    /** F1 (UAT review 2026-08-12) — "Tanda Terima Invoice": same filters as index(), unpaginated (listAll() — never truncated at the 100/page cap a single customer's invoice list could otherwise hit). */
    public function listAll(IndexAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['per_page']);

        return $this->success(AccountsReceivableResource::collection($this->accountsReceivableService->listAll($filters)));
    }

    /** C3 (UAT review 2026-08-12) — "Perincian Piutang": same filters as index(), grouped Sales Person -> Customer with subtotals. */
    public function detailGrouped(IndexAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['per_page']);

        return $this->success($this->accountsReceivableService->groupedDetail($filters));
    }

    /**
     * C2 (UAT review 2026-08-12), rebuilt to match the client's real legacy-system export files
     * ("Customer Detail Aging" / "Customer Summary Aging") — same filters as index(), unpaginated,
     * plus a `type` choice. `invoice_ids` (already validated/wired in filteredQuery()) doubles as
     * the "export only selected rows" mechanism — a non-empty selection narrows $rows the same way
     * a filter does, so every subtotal/Grand Total/Summary figure the report service computes
     * from $rows falls out already scoped to the selection, no separate code path needed.
     */
    public function export(IndexAccountsReceivableRequest $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'type' => ['sometimes', Rule::in(['detail', 'summary'])],
        ]);
        $format = $validated['format'] ?? 'xlsx';
        $type = $validated['type'] ?? 'detail';
        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->accountsReceivableAgingReportService->rows($filters);

        if ($type === 'summary') {
            $built = $this->accountsReceivableAgingReportService->summaryReport($rows, $format);
            $export = new AccountsReceivableAgingSummaryExport($built['rows'], $built['meta']);
            $filename = "CustomerSummaryAging.{$format}";
        } else {
            $built = $this->accountsReceivableAgingReportService->detailReport($rows, $format);
            $export = new AccountsReceivableAgingDetailExport($built['rows'], $built['meta']);
            $filename = "CustomerDetailAging.{$format}";
        }

        return Excel::download($export, $filename);
    }

    public function show(AccountsReceivable $accountsReceivable): JsonResponse
    {
        return $this->success(new AccountsReceivableResource($accountsReceivable->load(['customer', 'invoice', 'salesOrder', 'delivery'])));
    }
}
