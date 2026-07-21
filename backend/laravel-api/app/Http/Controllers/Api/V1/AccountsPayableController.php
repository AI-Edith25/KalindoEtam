<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAccountsPayableRequest;
use App\Http\Resources\AccountsPayableResource;
use App\Models\AccountsPayable;
use App\Services\AccountsPayableService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only — Accounts Payable rows are only ever created as a side
 * effect of GoodsReceiptService::submit(). No store/update/destroy.
 */
class AccountsPayableController extends Controller
{
    use ApiResponse;

    public function __construct(protected AccountsPayableService $accountsPayableService) {}

    public function index(IndexAccountsPayableRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(AccountsPayableResource::collection(
            $this->accountsPayableService->list($filters, $perPage)
        ));
    }

    public function show(AccountsPayable $accountsPayable): JsonResponse
    {
        return $this->success(new AccountsPayableResource($accountsPayable->load(['supplier', 'purchaseOrder', 'goodsReceipt'])));
    }
}
