<?php

namespace App\Services;

use App\Models\Item;
use App\Repositories\SalesOrderItemRepository;

/**
 * Sales Order stock-availability block — the one place "can this warehouse actually cover this
 * order's lines" is decided, reused by SalesOrderService::enforceStockCheck() (create/update/
 * approve). Mirrors CustomerCreditService's shape exactly: a pure evaluate() that never throws,
 * leaving the block-or-allow decision (and the override/permission check) to the caller.
 */
class SalesOrderStockService
{
    public function __construct(
        protected StockLedgerService $stockLedgerService,
        protected SalesOrderItemRepository $salesOrderItemRepository,
    ) {}

    /**
     * $excludeSalesOrderId omits the order being edited from its own committed-qty count — see
     * SalesOrderItemRepository::committedQtyByItem().
     *
     * @param  array<int, array{item_id: string, qty: int|float}>  $lines
     * @return array{is_blocked: bool, message: string, lines: array<int, array{item_id: string, item_name: string, requested_qty: float, physical_qty: float, committed_qty: float, available_qty: float, is_insufficient: bool}>}
     */
    public function evaluate(array $lines, string $warehouseId, ?string $excludeSalesOrderId = null): array
    {
        $itemIds = collect($lines)->pluck('item_id')->unique()->values()->all();

        if ($itemIds === []) {
            return ['is_blocked' => false, 'message' => '', 'lines' => []];
        }

        $physical = $this->stockLedgerService->peekBalances($itemIds, $warehouseId);
        $committed = $this->salesOrderItemRepository->committedQtyByItem($itemIds, $warehouseId, $excludeSalesOrderId);
        $itemNames = Item::query()->whereIn('id', $itemIds)->pluck('item_name', 'id');

        $results = [];
        $messages = [];

        foreach ($lines as $line) {
            $physicalQty = $physical[$line['item_id']] ?? 0.0;
            $committedQty = $committed[$line['item_id']] ?? 0.0;
            $availableQty = $physicalQty - $committedQty;
            $requestedQty = (float) $line['qty'];
            $isInsufficient = $requestedQty > $availableQty;
            $itemName = $itemNames->get($line['item_id'], $line['item_id']);

            if ($isInsufficient) {
                $messages[] = "Stok tidak mencukupi untuk item {$itemName}. Stok tersedia: {$this->trim($availableQty)}, diminta: {$this->trim($requestedQty)}.";
            }

            $results[] = [
                'item_id' => $line['item_id'],
                'item_name' => $itemName,
                'requested_qty' => $requestedQty,
                'physical_qty' => $physicalQty,
                'committed_qty' => $committedQty,
                'available_qty' => $availableQty,
                'is_insufficient' => $isInsufficient,
            ];
        }

        return ['is_blocked' => $messages !== [], 'message' => implode(' ', $messages), 'lines' => $results];
    }

    private function trim(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0';
    }
}
