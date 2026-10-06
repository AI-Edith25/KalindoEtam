<?php

namespace App\Services;

use App\Models\Item;

/**
 * Sales Order stock warning — compares each line against the warehouse's physical stock only.
 * A Sales Order does not reserve stock: only a Delivery moves it (DeliveryService::assertSufficientStock()).
 * Reused by SalesOrderService::enforceStockCheck() (create/update/approve). A pure evaluate() that
 * never throws, leaving the block-or-allow decision (and the override/permission check) to the caller.
 */
class SalesOrderStockService
{
    public function __construct(
        protected StockLedgerService $stockLedgerService,
    ) {}

    /**
     * @param  array<int, array{item_id: string, qty: int|float}>  $lines
     * @return array{is_blocked: bool, message: string, lines: array<int, array{item_id: string, item_name: string, requested_qty: float, physical_qty: float, available_qty: float, is_insufficient: bool}>}
     */
    public function evaluate(array $lines, string $warehouseId): array
    {
        $itemIds = collect($lines)->pluck('item_id')->unique()->values()->all();

        if ($itemIds === []) {
            return ['is_blocked' => false, 'message' => '', 'lines' => []];
        }

        $physical = $this->stockLedgerService->peekBalances($itemIds, $warehouseId);
        $itemNames = Item::query()->whereIn('id', $itemIds)->pluck('item_name', 'id');

        $results = [];
        $messages = [];

        foreach ($lines as $line) {
            $physicalQty = $physical[$line['item_id']] ?? 0.0;
            // Base (stock) units — a line in DUS asks for qty × factor KG of stock.
            $requestedQty = (float) $line['qty'] * (float) ($line['uom_factor'] ?? 1);
            $isInsufficient = $requestedQty > $physicalQty;
            $itemName = $itemNames->get($line['item_id'], $line['item_id']);

            if ($isInsufficient) {
                $messages[] = "Stok tidak mencukupi untuk item {$itemName}. Stok tersedia: {$this->trim($physicalQty)}, diminta: {$this->trim($requestedQty)}.";
            }

            $results[] = [
                'item_id' => $line['item_id'],
                'item_name' => $itemName,
                'requested_qty' => $requestedQty,
                'physical_qty' => $physicalQty,
                'available_qty' => $physicalQty,
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
