<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAccountsReceivableRequest;
use App\Http\Resources\AccountsReceivableResource;
use App\Models\AccountsReceivable;
use App\Services\AccountsReceivableService;
use Illuminate\Http\JsonResponse;

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

        return $this->success(AccountsReceivableResource::collection(
            $this->accountsReceivableService->list($filters, $perPage)
        ));
    }

    public function show(AccountsReceivable $accountsReceivable): JsonResponse
    {
        return $this->success(new AccountsReceivableResource($accountsReceivable->load(['customer', 'invoice', 'salesOrder', 'delivery'])));
    }
}
