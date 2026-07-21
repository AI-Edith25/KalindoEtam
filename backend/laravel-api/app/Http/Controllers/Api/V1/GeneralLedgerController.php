<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexGeneralLedgerRequest;
use App\Http\Requests\ShowGeneralLedgerAccountRequest;
use App\Http\Resources\ChartOfAccountResource;
use App\Http\Resources\LedgerAccountSummaryResource;
use App\Http\Resources\LedgerLineResource;
use App\Models\ChartOfAccount;
use App\Services\GeneralLedgerService;
use Illuminate\Http\JsonResponse;

/** Read-only — no store/update/destroy anywhere on this controller. See docs/GENERAL_LEDGER_DESIGN.md. */
class GeneralLedgerController extends Controller
{
    use ApiResponse;

    public function __construct(protected GeneralLedgerService $generalLedgerService) {}

    public function accounts(IndexGeneralLedgerRequest $request): JsonResponse
    {
        $rows = $this->generalLedgerService->listAccounts($request->validated());

        return $this->success(LedgerAccountSummaryResource::collection($rows));
    }

    public function ledger(ShowGeneralLedgerAccountRequest $request, ChartOfAccount $chartOfAccount): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        $result = $this->generalLedgerService->accountLedger($chartOfAccount, $filters, $perPage);
        $paginator = $result['paginator'];

        return response()->json([
            'success' => true,
            'message' => '',
            'data' => [
                'account' => new ChartOfAccountResource($result['account']),
                'opening_balance' => $result['opening_balance'],
                'ending_balance' => $result['ending_balance'],
                'lines' => LedgerLineResource::collection($paginator->items()),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
