<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBankReconciliationSummaryRequest;
use App\Http\Requests\IndexBankStatementLineRequest;
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
            ->getDailyBalancingSummary($data['bank_account_id'] ?? null, $data['date_from'], $data['date_to'])
            ->load('bankAccount');

        return $this->success(BankReconciliationSummaryResource::collection($summaries));
    }

    /** Statement lines vs matched documents for one bank account + day -- the detail page's drill-down. */
    public function lines(IndexBankStatementLineRequest $request): JsonResponse
    {
        $data = $request->validated();
        $lines = BankStatementLine::query()
            ->whereHas('bankStatement', fn ($q) => $q->where('bank_account_id', $data['bank_account_id']))
            ->whereDate('transaction_date', $data['date'])
            ->with('matchedDocument')
            ->orderBy('transaction_date')
            ->get();

        return $this->success(BankStatementLineResource::collection($lines));
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
