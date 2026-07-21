<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrderItem;
use App\Repositories\GoodsReceiptItemRepository;
use App\Repositories\GoodsReceiptRepository;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\PurchaseOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GoodsReceiptService
{
    public function __construct(
        protected GoodsReceiptRepository $goodsReceiptRepository,
        protected GoodsReceiptItemRepository $goodsReceiptItemRepository,
        protected PurchaseOrderRepository $purchaseOrderRepository,
        protected PurchaseOrderItemRepository $purchaseOrderItemRepository,
        protected StockLedgerService $stockLedgerService,
        protected AccountsPayableService $accountsPayableService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->goodsReceiptRepository->search($filters, $perPage);
    }

    public function create(array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($data) {
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

            foreach ($data['items'] as $line) {
                $poItem = $this->resolvePurchaseOrderItem($purchaseOrder->id, $line['purchase_order_item_id']);
                $this->assertWithinOutstanding($poItem, $line['qty']);

                $item = $poItem->item;

                $this->goodsReceiptItemRepository->create([
                    'goods_receipt_id' => $goodsReceipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'item_id' => $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'uom' => $item->uom->name,
                    'qty' => $line['qty'],
                    'rate' => $poItem->rate,
                    'amount' => $line['qty'] * $poItem->rate,
                ]);
            }

            $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'purchaseOrder', 'items']);
            $this->auditLogService->record('created', 'goods_receipt', "Created Goods Receipt \"{$goodsReceipt->document_number}\".");

            return $goodsReceipt;
        });
    }

    public function update(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt, $data) {
            $this->assertDraft($goodsReceipt, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $goodsReceipt->items()->delete();

                foreach ($data['items'] as $line) {
                    $poItem = $this->resolvePurchaseOrderItem($goodsReceipt->purchase_order_id, $line['purchase_order_item_id']);
                    $this->assertWithinOutstanding($poItem, $line['qty']);

                    $item = $poItem->item;

                    $this->goodsReceiptItemRepository->create([
                        'goods_receipt_id' => $goodsReceipt->id,
                        'purchase_order_item_id' => $poItem->id,
                        'item_id' => $item->id,
                        'item_code' => $item->item_code,
                        'item_name' => $item->item_name,
                        'uom' => $item->uom->name,
                        'qty' => $line['qty'],
                        'rate' => $poItem->rate,
                        'amount' => $line['qty'] * $poItem->rate,
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
     * advance the PO's received_qty, flip status via Documentable, then
     * create the Accounts Payable record.
     */
    public function submit(GoodsReceipt $goodsReceipt): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt) {
            $goodsReceipt->load(['items.purchaseOrderItem', 'purchaseOrder']);

            if ($goodsReceipt->purchaseOrder->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException('Purchase Order is no longer submitted; cannot receive goods against it.');
            }

            foreach ($goodsReceipt->items as $line) {
                $this->assertWithinOutstanding($line->purchaseOrderItem, $line->qty);
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

                $this->purchaseOrderItemRepository->incrementReceivedQty($line->purchaseOrderItem, $line->qty);
            }

            $goodsReceipt->submit();

            $this->accountsPayableService->createFromGoodsReceipt($goodsReceipt);

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

    protected function assertWithinOutstanding(PurchaseOrderItem $poItem, int $qty): void
    {
        $outstanding = $poItem->qty - $poItem->received_qty;

        if ($qty > $outstanding) {
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
