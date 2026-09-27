<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexBankReconciliationDayDetailRequest;
use App\Http\Requests\IndexBankReconciliationSummaryRequest;
use App\Http\Requests\RecomputeBankReconciliationRequest;
use App\Http\Resources\BankReconciliationSummaryResource;
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
        $summaries = $this->bankReconciliationService->getDailyBalancingSummary($data['date_from'], $data['date_to']);

        return $this->success(BankReconciliationSummaryResource::collection($summaries));
    }

    /** "View" action (⋮ menu) on a summary row -- uploaded file(s) for that day plus that day's Cash Book rows. */
    public function dayDetail(IndexBankReconciliationDayDetailRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->success($this->bankReconciliationService->dayDetail($data['date']));
    }

    /** Detail tab: Cash Book vs uploaded bank statement, compared at the day-total level, for one day. */
    public function comparisonRows(IndexBankReconciliationDayDetailRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->success($this->bankReconciliationService->comparisonRows($data['date']));
    }

    /** "Re-run reconciliation" — rebuilds the summary for an explicit range. */
    public function recompute(RecomputeBankReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->bankReconciliationService->recomputeSummary($data['date_from'], $data['date_to']);

        return $this->success(null, 'Reconciliation recomputed.');
    }
}
