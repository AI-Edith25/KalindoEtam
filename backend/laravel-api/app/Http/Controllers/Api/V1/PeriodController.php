<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAccountingPeriodRequest;
use App\Http\Requests\StoreFiscalYearRequest;
use App\Http\Resources\AccountingPeriodResource;
use App\Http\Resources\FiscalYearResource;
use App\Models\AccountingPeriod;
use App\Services\PeriodManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Period Closing — a locking mechanism only, never a create/update/delete surface over journal_entries. See docs/PERIOD_CLOSING_DESIGN.md. */
class PeriodController extends Controller
{
    use ApiResponse;

    public function __construct(protected PeriodManagementService $periodManagementService) {}

    public function fiscalYears(): JsonResponse
    {
        return $this->success(FiscalYearResource::collection($this->periodManagementService->listFiscalYears()));
    }

    public function storeFiscalYear(StoreFiscalYearRequest $request): JsonResponse
    {
        $fiscalYear = $this->periodManagementService->createFiscalYear($request->validated());

        return $this->success(new FiscalYearResource($fiscalYear), 'Fiscal year created.', 201);
    }

    public function periods(IndexAccountingPeriodRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(AccountingPeriodResource::collection(
            $this->periodManagementService->listPeriods($filters, $perPage)
        ));
    }

    public function validatePeriod(AccountingPeriod $accountingPeriod): JsonResponse
    {
        return $this->success(['checks' => $this->periodManagementService->runValidation($accountingPeriod)]);
    }

    public function close(Request $request, AccountingPeriod $accountingPeriod): JsonResponse
    {
        $period = $this->periodManagementService->close($accountingPeriod, $request->user());

        return $this->success(new AccountingPeriodResource($period->load(['fiscalYear', 'closedBy', 'reopenedBy'])), 'Accounting period closed.');
    }

    public function reopen(Request $request, AccountingPeriod $accountingPeriod): JsonResponse
    {
        $period = $this->periodManagementService->reopen($accountingPeriod, $request->user());

        return $this->success(new AccountingPeriodResource($period->load(['fiscalYear', 'closedBy', 'reopenedBy'])), 'Accounting period reopened.');
    }
}
