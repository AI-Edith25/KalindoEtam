<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\AccountsReceivableExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAccountsReceivableRequest;
use App\Http\Resources\AccountsReceivableResource;
use App\Models\AccountsReceivable;
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

    public function __construct(protected AccountsReceivableService $accountsReceivableService) {}

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

    /** C3 (UAT review 2026-08-12) — "Perincian Piutang": same filters as index(), grouped Sales Person -> Customer with subtotals. */
    public function detailGrouped(IndexAccountsReceivableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['per_page']);

        return $this->success($this->accountsReceivableService->groupedDetail($filters));
    }

    /** C2 (UAT review 2026-08-12) — XLSX/CSV export, same filters as index(), unpaginated (AccountsReceivableService::listAll()). */
    public function export(IndexAccountsReceivableRequest $request): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->accountsReceivableService->listAll($filters);

        return Excel::download(new AccountsReceivableExport($rows), "ar-detail.{$format}");
    }

    public function show(AccountsReceivable $accountsReceivable): JsonResponse
    {
        return $this->success(new AccountsReceivableResource($accountsReceivable->load(['customer', 'invoice', 'salesOrder', 'delivery'])));
    }
}
