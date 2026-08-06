<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAccountsReceivableAgingRequest;
use App\Http\Resources\AccountsReceivableAgingRowResource;
use App\Services\AccountsReceivableService;
use Illuminate\Http\JsonResponse;

/** Read-only — no store/update/destroy. Presentation layer over AccountsReceivableService::summarizeAging(). */
class AccountsReceivableAgingController extends Controller
{
    use ApiResponse;

    public function __construct(protected AccountsReceivableService $accountsReceivableService) {}

    public function summary(IndexAccountsReceivableAgingRequest $request): JsonResponse
    {
        $result = $this->accountsReceivableService->summarizeAging($request->validated());

        return $this->success([
            'rows' => AccountsReceivableAgingRowResource::collection($result['rows']),
            'totals' => $result['totals'],
            'as_of_date' => $result['as_of_date'],
        ]);
    }
}
