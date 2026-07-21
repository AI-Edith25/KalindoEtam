<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardDateRangeRequest;
use App\Http\Requests\DashboardDateRequest;
use App\Http\Requests\DashboardFinancialSummaryRequest;
use App\Http\Requests\LowStockItemsRequest;
use App\Http\Requests\RecentTransactionsRequest;
use App\Http\Resources\ItemResource;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(protected DashboardService $dashboardService) {}

    public function stockSummary(): JsonResponse
    {
        return $this->success($this->dashboardService->stockSummary());
    }

    public function purchasesToday(DashboardDateRequest $request): JsonResponse
    {
        return $this->success($this->dashboardService->purchasesForDate($request->resolvedDate()));
    }

    public function salesToday(DashboardDateRequest $request): JsonResponse
    {
        return $this->success($this->dashboardService->salesForDate($request->resolvedDate()));
    }

    public function accountsPayableOutstanding(): JsonResponse
    {
        return $this->success($this->dashboardService->accountsPayableOutstanding());
    }

    public function accountsReceivableOutstanding(): JsonResponse
    {
        return $this->success($this->dashboardService->accountsReceivableOutstanding());
    }

    public function lowStockItems(LowStockItemsRequest $request): JsonResponse
    {
        return $this->success(ItemResource::collection(
            $this->dashboardService->lowStockItems($request->resolvedThreshold())
        ));
    }

    public function recentTransactions(RecentTransactionsRequest $request): JsonResponse
    {
        return $this->success($this->dashboardService->recentTransactions($request->resolvedLimit()));
    }

    public function financialSummary(DashboardFinancialSummaryRequest $request): JsonResponse
    {
        return $this->success($this->dashboardService->financialSummary($request->resolvedFilters()));
    }

    public function pendingTasks(): JsonResponse
    {
        return $this->success($this->dashboardService->pendingTasks());
    }

    public function salesTrend(DashboardDateRangeRequest $request): JsonResponse
    {
        return $this->success($this->dashboardService->salesTrend($request->resolvedDateFrom(), $request->resolvedDateTo()));
    }

    public function purchaseTrend(DashboardDateRangeRequest $request): JsonResponse
    {
        return $this->success($this->dashboardService->purchaseTrend($request->resolvedDateFrom(), $request->resolvedDateTo()));
    }

    public function inventoryMovement(DashboardDateRangeRequest $request): JsonResponse
    {
        return $this->success($this->dashboardService->inventoryMovement($request->resolvedDateFrom(), $request->resolvedDateTo()));
    }
}
