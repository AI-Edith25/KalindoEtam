<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\AccountsPayableAgingDetailExport;
use App\Exports\AccountsPayableAgingSummaryExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAccountsPayableRequest;
use App\Http\Resources\AccountsPayableResource;
use App\Models\AccountsPayable;
use App\Models\PaymentEntry;
use App\Services\AccountsPayableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only — Accounts Payable rows are only ever created as a side
 * effect of PurchaseInvoiceService::submit(). No store/update/destroy.
 * Mirrors AccountsReceivableController's shape.
 */
class AccountsPayableController extends Controller
{
    use ApiResponse;

    public function __construct(protected AccountsPayableService $accountsPayableService) {}

    public function index(IndexAccountsPayableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(
            AccountsPayableResource::collection($this->accountsPayableService->list($filters, $perPage)),
            '',
            200,
            ['total_outstanding' => $this->accountsPayableService->outstandingTotal($filters)]
        );
    }

    /** Same filters as index(), unpaginated — for AP Detail's Export. Mirrors AccountsReceivableController::listAll(). */
    public function listAll(IndexAccountsPayableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['per_page']);

        return $this->success(AccountsPayableResource::collection($this->accountsPayableService->listAll($filters)));
    }

    /** "Perincian Hutang": same filters as index(), grouped by Supplier with due-date-anchored aging buckets. Mirrors AccountsReceivableController::detailGrouped(). */
    public function groupedDetail(IndexAccountsPayableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['per_page']);

        return $this->success($this->accountsPayableService->groupedDetail($filters));
    }

    /** AP Detail's 4 summary cards — one request instead of the frontend issuing 4 separate filtered index() calls just to read totals. */
    public function summary(): JsonResponse
    {
        return $this->success($this->accountsPayableService->summaryCards());
    }

    /** "Uang Muka / Belum Teralokasi" panel — Supplier Payment Vouchers not yet applied to any invoice. */
    public function unallocated(): JsonResponse
    {
        $rows = $this->accountsPayableService->unallocatedPaymentVouchers()->map(fn (PaymentEntry $entry) => [
            'id' => $entry->id,
            'payment_date' => $entry->payment_date?->format('Y-m-d'),
            'document_number' => $entry->document_number,
            'supplier_name' => $entry->supplier?->supplier_name,
            'unallocated_amount' => $entry->unallocated_amount_computed,
            'payment_method' => $entry->cashAccount?->name ?? ucwords(str_replace('_', ' ', $entry->payment_method?->value ?? '')),
        ]);

        return $this->success($rows);
    }

    /** Same filters as index(), unpaginated, plus a `type` choice. No legacy Excel file to match (unlike AR), so both types share one bucket definition. */
    public function export(IndexAccountsPayableRequest $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'type' => ['sometimes', Rule::in(['detail', 'summary'])],
        ]);
        $format = $validated['format'] ?? 'xlsx';
        $type = $validated['type'] ?? 'detail';
        $filters = $request->validated();
        unset($filters['per_page']);

        if ($type === 'summary') {
            $export = new AccountsPayableAgingSummaryExport($this->accountsPayableService->groupedDetail($filters));
            $filename = "SupplierSummaryAging.{$format}";
        } else {
            $export = new AccountsPayableAgingDetailExport($this->accountsPayableService->listAll($filters));
            $filename = "SupplierDetailAging.{$format}";
        }

        return Excel::download($export, $filename);
    }

    public function show(AccountsPayable $accountsPayable): JsonResponse
    {
        $accountsPayable->setAttribute('paid_amount_computed', $this->accountsPayableService->paidAmountFor($accountsPayable->id));

        return $this->success(new AccountsPayableResource($accountsPayable->load(['supplier', 'purchaseOrder', 'purchaseInvoice', 'goodsReceipt.warehouse'])));
    }
}
