<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBankReconciliationDetailRequest;
use App\Http\Requests\IndexBankReconciliationSummaryRequest;
use App\Http\Requests\ManualMatchBankStatementLineRequest;
use App\Http\Requests\RecomputeBankReconciliationRequest;
use App\Http\Resources\BankReconciliationSummaryResource;
use App\Http\Resources\BankStatementLineResource;
use App\Models\BankStatementLine;
use App\Services\BankStatement\BankReconciliationService;
use Illuminate\Http\JsonResponse;

class BankReconciliationController extends Controller
{
    use ApiResponse;

    public function __construct(private BankReconciliationService $bankReconciliationService) {}

    /** Daily balancing table -- backs both the Dashboard widget and the detail page's summary rows. */
    public function index(IndexBankReconciliationSummaryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $summaries = $this->bankReconciliationService
            ->getDailyBalancingSummary($data['bank_account_id'] ?? null, $data['date_from'], $data['date_to']);

        return $this->success(BankReconciliationSummaryResource::collection($summaries));
    }

    /**
     * The detail page's main transaction list, either side: "import" (uploaded statement lines,
     * System = their matched document if any) or "system" (Payment Voucher/Official Receipt in
     * range, Statement = the line they're matched to if any). Same six-column shape either way.
     */
    public function lines(IndexBankReconciliationDetailRequest $request): JsonResponse
    {
        $data = $request->validated();
        $view = $data['view'] ?? 'import';
        $bankAccountId = $data['bank_account_id'] ?? null;

        $rows = $view === 'system'
            ? $this->bankReconciliationService->systemRows($bankAccountId, $data['date_from'], $data['date_to'])
            : $this->bankReconciliationService->importRows($bankAccountId, $data['date_from'], $data['date_to']);

        return $this->success($rows);
    }

    /** "Re-run reconciliation" — re-matches and rebuilds the summary for an explicit range. */
    public function recompute(RecomputeBankReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->bankReconciliationService->match($data['bank_account_id'], $data['date_from'], $data['date_to'], $data['tolerance_days'] ?? 0);
        $this->bankReconciliationService->recomputeSummary($data['bank_account_id'], $data['date_from'], $data['date_to']);

        return $this->success(null, 'Reconciliation recomputed.');
    }

    public function manualMatch(ManualMatchBankStatementLineRequest $request, BankStatementLine $bankStatementLine): JsonResponse
    {
        $data = $request->validated();
        $this->bankReconciliationService->manualMatch($bankStatementLine, $data['document_type'], $data['document_id']);

        return $this->success(new BankStatementLineResource($bankStatementLine->refresh()->load('matchedDocument')), 'Line matched.');
    }
}
