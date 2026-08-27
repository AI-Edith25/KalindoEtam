<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\PurchaseOrderItem;
use App\Repositories\GoodsReceiptItemRepository;
use App\Repositories\GoodsReceiptRepository;
use App\Repositories\ItemRepository;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\PurchaseOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class GoodsReceiptService
{
    public function __construct(
        protected GoodsReceiptRepository $goodsReceiptRepository,
        protected GoodsReceiptItemRepository $goodsReceiptItemRepository,
        protected PurchaseOrderRepository $purchaseOrderRepository,
        protected PurchaseOrderItemRepository $purchaseOrderItemRepository,
        protected ItemRepository $itemRepository,
        protected StockLedgerService $stockLedgerService,
        protected AuditLogService $auditLogService,
        protected QtyCategoryValidator $qtyCategoryValidator,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->goodsReceiptRepository->search($filters, $perPage);
    }

    /** Unpaginated, same filters as list() — for export. */
    public function listAll(array $filters = []): Collection
    {
        return $this->goodsReceiptRepository->searchAll($filters);
    }

    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['purchase_order_id'])) {
                return $this->createDirect($data);
            }

            $purchaseOrder = $this->purchaseOrderRepository->findOrFail($data['purchase_order_id']);

            if ($purchaseOrder->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException('Purchase Order must be submitted before a Goods Receipt can be created against it.');
            }

            $goodsReceipt = $this->goodsReceiptRepository->create([
                'purchase_order_id' => $purchaseOrder->id,
                'supplier_id' => $purchaseOrder->supplier_id,
                'warehouse_id' => $data['warehouse_id'],
                'receipt_date' => $data['receipt_date'],
                'due_date' => $data['due_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->assertAggregateWithinOutstanding($purchaseOrder->id, $data['items']);

            foreach ($data['items'] as $line) {
                $poItem = $this->resolvePurchaseOrderItem($purchaseOrder->id, $line['purchase_order_item_id']);
                $item = $poItem->item;
                $this->qtyCategoryValidator->assertValid($item, $line['qty']);
                $qty = $this->qtyCategoryValidator->round($item, $line['qty']);

                $this->goodsReceiptItemRepository->create([
                    'goods_receipt_id' => $goodsReceipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'item_id' => $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'uom' => $item->uom->name,
                    'qty' => $qty,
                    'qty_category' => $item->qty_category,
                    'rate' => $poItem->rate,
                    'amount' => $qty * $poItem->rate,
                ]);
            }

            $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'purchaseOrder', 'items']);
            $this->auditLogService->record('created', 'goods_receipt', "Created Goods Receipt \"{$goodsReceipt->document_number}\".");

            return $goodsReceipt;
        });
    }

    /**
     * Standalone receipt with no source Purchase Order — items are typed
     * directly (item/qty/rate) instead of copied from PO lines, so there's
     * no assertWithinOutstanding()/incrementReceivedQty() to run (nothing
     * to check against). Only reachable from create(), always inside its
     * transaction.
     */
    protected function createDirect(array $data): GoodsReceipt
    {
        $goodsReceipt = $this->goodsReceiptRepository->create([
            'purchase_order_id' => null,
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'],
            'receipt_date' => $data['receipt_date'],
            'due_date' => $data['due_date'],
            'remarks' => $data['remarks'] ?? null,
        ]);

        foreach ($data['items'] as $line) {
            $this->createDirectLine($goodsReceipt, $line);
        }

        $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'items']);
        $this->auditLogService->record('created', 'goods_receipt', "Created Goods Receipt \"{$goodsReceipt->document_number}\".");

        return $goodsReceipt;
    }

    protected function createDirectLine(GoodsReceipt $goodsReceipt, array $line): void
    {
        $item = $this->itemRepository->findOrFail($line['item_id']);
        $this->qtyCategoryValidator->assertValid($item, $line['qty']);
        $qty = $this->qtyCategoryValidator->round($item, $line['qty']);

        $this->goodsReceiptItemRepository->create([
            'goods_receipt_id' => $goodsReceipt->id,
            'purchase_order_item_id' => null,
            'item_id' => $item->id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'uom' => $item->uom->name,
            'qty' => $qty,
            'qty_category' => $item->qty_category,
            'rate' => $line['rate'],
            'amount' => $qty * $line['rate'],
        ]);
    }

    public function update(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt, $data) {
            $this->assertDraft($goodsReceipt, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $goodsReceipt->items()->delete();

                if ($goodsReceipt->purchase_order_id !== null) {
                    $this->assertAggregateWithinOutstanding($goodsReceipt->purchase_order_id, $data['items']);
                }

                foreach ($data['items'] as $line) {
                    if ($goodsReceipt->purchase_order_id === null) {
                        $this->createDirectLine($goodsReceipt, $line);

                        continue;
                    }

                    $poItem = $this->resolvePurchaseOrderItem($goodsReceipt->purchase_order_id, $line['purchase_order_item_id']);
                    $item = $poItem->item;
                    $this->qtyCategoryValidator->assertValid($item, $line['qty']);
                    $qty = $this->qtyCategoryValidator->round($item, $line['qty']);

                    $this->goodsReceiptItemRepository->create([
                        'goods_receipt_id' => $goodsReceipt->id,
                        'purchase_order_item_id' => $poItem->id,
                        'item_id' => $item->id,
                        'item_code' => $item->item_code,
                        'item_name' => $item->item_name,
                        'uom' => $item->uom->name,
                        'qty' => $qty,
                        'qty_category' => $item->qty_category,
                        'rate' => $poItem->rate,
                        'amount' => $qty * $poItem->rate,
                    ]);
                }
            }

            $this->goodsReceiptRepository->update($goodsReceipt, $headerData);

            $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'purchaseOrder', 'items']);
            $this->auditLogService->record('updated', 'goods_receipt', "Updated Goods Receipt \"{$goodsReceipt->document_number}\".");

            return $goodsReceipt;
        });
    }

    public function delete(GoodsReceipt $goodsReceipt): void
    {
        DB::transaction(function () use ($goodsReceipt) {
            $this->assertDraft($goodsReceipt, 'deleted');
            $documentNumber = $goodsReceipt->document_number;
            $this->goodsReceiptRepository->delete($goodsReceipt);
            $this->auditLogService->record('deleted', 'goods_receipt', "Deleted Goods Receipt \"{$documentNumber}\".");
        });
    }

    /**
     * The workflow: validate PO, move stock (StockLedgerService only),
     * advance the PO's received_qty, flip status via Documentable. Stock-
     * only — Accounts Payable and the GL posting no longer happen here,
     * they're created when a Purchase Invoice billed against this Goods
     * Receipt is submitted (PurchaseInvoiceService::submit()).
     */
    public function submit(GoodsReceipt $goodsReceipt): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt) {
            $goodsReceipt->load(['items.purchaseOrderItem.item', 'purchaseOrder']);

            if ($goodsReceipt->purchase_order_id !== null) {
                if ($goodsReceipt->purchaseOrder->status !== DocumentStatus::SUBMITTED) {
                    throw new BusinessException('Purchase Order is no longer submitted; cannot receive goods against it.');
                }

                $goodsReceipt->items
                    ->groupBy('purchase_order_item_id')
                    ->each(function ($group) {
                        $this->assertWithinOutstanding($group->first()->purchaseOrderItem, (float) $group->sum('qty'));
                    });
            }

            foreach ($goodsReceipt->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $goodsReceipt->warehouse_id,
                    transactionType: StockTransactionType::IN,
                    voucherType: StockVoucherType::GOODS_RECEIPT,
                    voucherId: $goodsReceipt->id,
                    qtyChange: $line->qty,
                    postingDatetime: now(),
                    referenceNo: $goodsReceipt->document_number,
                    remarks: "Goods Receipt {$goodsReceipt->document_number}",
                );

                if ($line->purchaseOrderItem !== null) {
                    $this->purchaseOrderItemRepository->incrementReceivedQty($line->purchaseOrderItem, $line->qty);
                }
            }

            $goodsReceipt->submit();

            $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'purchaseOrder', 'items']);
            $this->auditLogService->record('submitted', 'goods_receipt', "Submitted Goods Receipt \"{$goodsReceipt->document_number}\".");

            return $goodsReceipt;
        });
    }

    protected function resolvePurchaseOrderItem(string $purchaseOrderId, string $purchaseOrderItemId): PurchaseOrderItem
    {
        $poItem = $this->purchaseOrderItemRepository->findOrFail($purchaseOrderItemId);

        if ($poItem->purchase_order_id !== $purchaseOrderId) {
            throw new BusinessException('Purchase Order item does not belong to the specified Purchase Order.');
        }

        return $poItem;
    }

    /**
     * The same PO item can appear on more than one line (different truck
     * loads) — validate the combined qty per item, not each line alone,
     * against that item's outstanding qty.
     */
    protected function assertAggregateWithinOutstanding(string $purchaseOrderId, array $lines): void
    {
        $totalsByPoItemId = collect($lines)
            ->groupBy('purchase_order_item_id')
            ->map(fn ($group) => collect($group)->sum('qty'));

        foreach ($totalsByPoItemId as $purchaseOrderItemId => $totalQty) {
            $poItem = $this->resolvePurchaseOrderItem($purchaseOrderId, $purchaseOrderItemId);
            $this->assertWithinOutstanding($poItem, (float) $totalQty);
        }
    }

    protected function assertWithinOutstanding(PurchaseOrderItem $poItem, int|float $qty): void
    {
        $outstanding = $poItem->qty - $poItem->received_qty;

        if ($qty > $outstanding && ! $poItem->item->allow_over_receipt) {
            throw new BusinessException("Received qty ({$qty}) exceeds outstanding qty ({$outstanding}) for item {$poItem->item->item_code}.");
        }
    }

    protected function assertDraft(GoodsReceipt $goodsReceipt, string $action): void
    {
        if ($goodsReceipt->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Goods Receipts can be {$action}.");
        }
    }
}
