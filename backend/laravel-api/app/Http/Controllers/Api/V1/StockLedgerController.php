<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexStockBalanceReportRequest;
use App\Http\Requests\IndexStockBalanceRequest;
use App\Http\Requests\IndexStockLedgerRequest;
use App\Http\Resources\StockBalanceResource;
use App\Http\Resources\StockLedgerResource;
use App\Models\Item;
use App\Services\StockLedgerService;
use Illuminate\Http\JsonResponse;

class StockLedgerController extends Controller
{
    use ApiResponse;

    public function __construct(protected StockLedgerService $stockLedgerService) {}

    public function index(Item $item): JsonResponse
    {
        return $this->success(
            StockLedgerResource::collection($this->stockLedgerService->historyForItem($item->id))
        );
    }

    /** All ledger entries across every item/warehouse — the Inventory module's Stock Ledger report. Named list(), not index(), since index() is already taken by the item-scoped action above. */
    public function list(IndexStockLedgerRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(StockLedgerResource::collection(
            $this->stockLedgerService->listAll($filters, $perPage)
        ));
    }

    /**
     * Bulk, warehouse-scoped current balance for a set of items — thin
     * pass-through to StockLedgerService::getCurrentBalance() (already the
     * single source of truth used by DeliveryService::assertSufficientStock()
     * at submit time). Read-only, no new business logic. Reusable by any
     * future editor that needs to preview available stock before submitting
     * (Delivery today; Inventory/Stock Adjustment modules later).
     */
    public function balances(IndexStockBalanceRequest $request): JsonResponse
    {
        $warehouseId = $request->validated('warehouse_id');
        $itemIds = $request->validated('item_ids');

        $balances = collect($itemIds)->mapWithKeys(
            fn (string $itemId) => [$itemId => $this->stockLedgerService->getCurrentBalance($itemId, $warehouseId)]
        );

        return $this->success($balances);
    }

    /** One row per (item, warehouse) with any ledger history — the Inventory module's Stock Balance report. Distinct from balances() above (explicit item_ids, one warehouse, flat map) — this is paginated and filterable instead. */
    public function balancesReport(IndexStockBalanceReportRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(StockBalanceResource::collection(
            $this->stockLedgerService->currentBalances($filters, $perPage)
        ));
    }
}
