<?php

namespace App\Services;

use App\Enums\ProfitLossSection;
use App\Repositories\ApprovalFlowRepository;
use App\Repositories\AccountsPayableRepository;
use App\Repositories\AccountsReceivableRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\GoodsReceiptRepository;
use App\Repositories\ItemRepository;
use App\Repositories\JournalEntryRepository;
use App\Repositories\PaymentEntryRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\ReceiptEntryRepository;
use App\Repositories\SalesOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Read-only aggregation over existing entities — no new tables, no
 * business rules. Every number here is derived from data the six
 * workflow modules already produce. Sprint 23B (docs/DASHBOARD_DESIGN.md)
 * adds financialSummary()/pendingTasks()/salesTrend()/purchaseTrend()/
 * inventoryMovement() on the same discipline: each delegates to the one
 * existing service or repository that already owns that calculation —
 * ProfitLossService for Revenue/Expense, StockLedgerService for stock
 * movement — nothing here recomputes GL or stock math itself.
 */
class DashboardService
{
    public function __construct(
        protected ItemRepository $itemRepository,
        protected PurchaseOrderRepository $purchaseOrderRepository,
        protected SalesOrderRepository $salesOrderRepository,
        protected GoodsReceiptRepository $goodsReceiptRepository,
        protected DeliveryRepository $deliveryRepository,
        protected PaymentEntryRepository $paymentEntryRepository,
        protected ReceiptEntryRepository $receiptEntryRepository,
        protected AccountsPayableRepository $accountsPayableRepository,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected JournalEntryRepository $journalEntryRepository,
        protected ProfitLossService $profitLossService,
        protected StockLedgerService $stockLedgerService,
        protected ApprovalFlowRepository $approvalFlowRepository,
    ) {}

    public function stockSummary(): array
    {
        return $this->itemRepository->stockSummary();
    }

    public function purchasesForDate(string $date): array
    {
        return array_merge(['date' => $date], $this->purchaseOrderRepository->totalForDate($date));
    }

    public function salesForDate(string $date): array
    {
        return array_merge(['date' => $date], $this->salesOrderRepository->totalForDate($date));
    }

    public function accountsPayableOutstanding(): array
    {
        return $this->accountsPayableRepository->outstandingSummary();
    }

    public function accountsReceivableOutstanding(): array
    {
        return $this->accountsReceivableRepository->outstandingSummary();
    }

    public function lowStockItems(int $threshold, int $perPage = 15): LengthAwarePaginator
    {
        return $this->itemRepository->lowStock($threshold, $perPage);
    }

    /**
     * Merges the most recent documents across all six workflow entities
     * into one feed, sorted by creation time, trimmed to $limit. Each
     * repository is asked for at most $limit rows, so the merge cost is
     * bounded regardless of table size.
     */
    public function recentTransactions(int $limit = 20): array
    {
        $entries = collect();

        foreach ($this->purchaseOrderRepository->recent($limit) as $doc) {
            $entries->push($this->toEntry('purchase_order', $doc->document_number, $doc->order_date, (float) $doc->total_amount, $doc->status->value, $doc->created_at));
        }

        foreach ($this->goodsReceiptRepository->recent($limit) as $doc) {
            $entries->push($this->toEntry('goods_receipt', $doc->document_number, $doc->receipt_date, (float) $doc->items->sum('amount'), $doc->status->value, $doc->created_at));
        }

        foreach ($this->salesOrderRepository->recent($limit) as $doc) {
            $entries->push($this->toEntry('sales_order', $doc->document_number, $doc->order_date, (float) $doc->total_amount, $doc->status->value, $doc->created_at));
        }

        foreach ($this->deliveryRepository->recent($limit) as $doc) {
            $entries->push($this->toEntry('delivery', $doc->document_number, $doc->delivery_date, (float) $doc->items->sum('amount'), $doc->status->value, $doc->created_at));
        }

        foreach ($this->paymentEntryRepository->recent($limit) as $doc) {
            $entries->push($this->toEntry('payment_entry', $doc->document_number, $doc->payment_date, (float) $doc->total_amount, $doc->status->value, $doc->created_at));
        }

        foreach ($this->receiptEntryRepository->recent($limit) as $doc) {
            $entries->push($this->toEntry('receipt_entry', $doc->document_number, $doc->receipt_date, (float) $doc->total_amount, $doc->status->value, $doc->created_at));
        }

        return $entries->sortByDesc('created_at')->take($limit)->values()->all();
    }

    /**
     * Revenue/Expense/Net Profit for a period — the Financial Summary widget
     * and the Revenue vs Expense chart's only data source. Delegates
     * entirely to ProfitLossService::summarize(); this method only reduces
     * its section list to the three totals a KPI card needs. See
     * docs/DASHBOARD_DESIGN.md §3/§5.
     */
    public function financialSummary(array $filters): array
    {
        $result = $this->profitLossService->summarize($filters);
        $subtotals = collect($result['sections'])->pluck('subtotal', 'key');

        $revenueTotal = $subtotals[ProfitLossSection::REVENUE->value] + $subtotals[ProfitLossSection::OTHER_INCOME->value];
        $expenseTotal = $subtotals[ProfitLossSection::COST_OF_GOODS_SOLD->value]
            + $subtotals[ProfitLossSection::OPERATING_EXPENSE->value]
            + $subtotals[ProfitLossSection::OTHER_EXPENSE->value];

        return [
            'revenue_total' => round($revenueTotal, 2),
            'expense_total' => round($expenseTotal, 2),
            'net_profit' => $result['net_profit'],
        ];
    }

    /**
     * Draft/unposted counts across the three modules the design names
     * explicitly (docs/DASHBOARD_DESIGN.md §3) — each count reuses that
     * module's own repository; JournalEntryRepository::countDraftsBetween()
     * already existed (built for Period Closing) and is reused unbounded
     * rather than duplicated.
     */
    public function pendingTasks(): array
    {
        return [
            ['module' => 'purchase_order', 'label' => 'Draft Purchase Orders', 'count' => $this->purchaseOrderRepository->countDraft()],
            ['module' => 'sales_order', 'label' => 'Draft Sales Orders', 'count' => $this->salesOrderRepository->countDraft()],
            ['module' => 'journal_entry', 'label' => 'Unposted Journal Entries', 'count' => $this->journalEntryRepository->countDraftsBetween('1970-01-01', now()->toDateString())],
            // Sprint 24B (docs/APPROVAL_WORKFLOW_DESIGN.md §6) — one more row, identical shape,
            // delegating to ApprovalFlowRepository rather than recomputing anything here.
            ['module' => 'approval', 'label' => 'Pending Approval', 'count' => $this->approvalFlowRepository->countPending()],
        ];
    }

    /** Purchase Trend chart — delegates to PurchaseOrderRepository::totalsByDateRange(), no aggregation logic here. */
    public function purchaseTrend(string $dateFrom, string $dateTo): array
    {
        return $this->purchaseOrderRepository->totalsByDateRange($dateFrom, $dateTo)
            ->map(fn ($row) => ['date' => $row->date, 'total' => (float) $row->total, 'count' => (int) $row->count])
            ->values()->all();
    }

    /** Sales Trend chart — delegates to SalesOrderRepository::totalsByDateRange(), no aggregation logic here. */
    public function salesTrend(string $dateFrom, string $dateTo): array
    {
        return $this->salesOrderRepository->totalsByDateRange($dateFrom, $dateTo)
            ->map(fn ($row) => ['date' => $row->date, 'total' => (float) $row->total, 'count' => (int) $row->count])
            ->values()->all();
    }

    /** Inventory Movement chart — delegates to StockLedgerService::movementByDateRange(), no stock logic here. */
    public function inventoryMovement(string $dateFrom, string $dateTo): array
    {
        return $this->stockLedgerService->movementByDateRange($dateFrom, $dateTo)
            ->map(fn ($row) => ['date' => $row->date, 'stock_in' => (int) $row->stock_in, 'stock_out' => (int) $row->stock_out])
            ->values()->all();
    }

    protected function toEntry(string $type, ?string $documentNumber, ?Carbon $date, float $amount, string $status, ?Carbon $createdAt): array
    {
        return [
            'type' => $type,
            'document_number' => $documentNumber,
            'date' => $date?->format('Y-m-d'),
            'amount' => $amount,
            'status' => $status,
            'created_at' => $createdAt,
        ];
    }
}
