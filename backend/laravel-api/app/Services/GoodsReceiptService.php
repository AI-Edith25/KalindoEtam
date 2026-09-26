<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\QtyCategory;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\FifoLayer;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Repositories\GoodsReceiptItemRepository;
use App\Repositories\GoodsReceiptRepository;
use App\Repositories\ItemRepository;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\PurchaseOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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
        protected FifoLayerService $fifoLayerService,
        protected AuditLogService $auditLogService,
        protected QtyCategoryValidator $qtyCategoryValidator,
        protected TaxService $taxService,
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
                'due_date' => $this->resolveDueDate($data, $purchaseOrder->supplier_id, $data['receipt_date']),
                'remarks' => $data['remarks'] ?? null,
                'source_document_number' => $data['source_document_number'] ?? null,
            ]);

            $this->replaceItems($goodsReceipt, $data['items'], (bool) ($data['confirm_over_receipt'] ?? false));

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
            'due_date' => $this->resolveDueDate($data, $data['supplier_id'], $data['receipt_date']),
            'remarks' => $data['remarks'] ?? null,
            'source_document_number' => $data['source_document_number'] ?? null,
        ]);

        $this->replaceItems($goodsReceipt, $data['items'], false);

        $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'items']);
        $this->auditLogService->record('created', 'goods_receipt', "Created Goods Receipt \"{$goodsReceipt->document_number}\".");

        return $goodsReceipt;
    }

    protected function createDirectLine(GoodsReceipt $goodsReceipt, array $line): void
    {
        $item = $this->itemRepository->findOrFail($line['item_id']);
        $this->qtyCategoryValidator->assertValid($item, $line['qty']);
        $qty = $this->qtyCategoryValidator->round($item, $line['qty']);
        $amount = $qty * $line['rate'];
        // No Item default fallback (item: null) — a Direct Receipt line has no PO to inherit tax
        // from, so its Tax is purely optional/manual; omitting `tax_id` from the request leaves it
        // untaxed instead of silently defaulting to the Item's own purchase tax.
        [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, null, '', $amount);

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
            'amount' => $amount,
            'tax_id' => $taxId,
            'tax_amount' => $taxAmount,
        ]);
    }

    /**
     * Rebuilds $goodsReceipt's line items from request data — shared by create(), the draft
     * branch of update(), and updateSubmitted() (called after the old lines are already
     * cleared). PO-linked branch re-validates the aggregate outstanding qty and records
     * over-receipt; the Direct Receipt branch just types each line as given.
     */
    protected function replaceItems(GoodsReceipt $goodsReceipt, array $items, bool $confirmOverReceipt): void
    {
        if ($goodsReceipt->purchase_order_id === null) {
            foreach ($items as $line) {
                $this->createDirectLine($goodsReceipt, $line);
            }

            return;
        }

        $this->assertAggregateWithinOutstanding($goodsReceipt->purchase_order_id, $items, $confirmOverReceipt);
        $overReceiptByIndex = $this->computeOverReceiptQtyByIndex($goodsReceipt->purchase_order_id, $items);

        foreach ($items as $index => $line) {
            $poItem = $this->resolvePurchaseOrderItem($goodsReceipt->purchase_order_id, $line['purchase_order_item_id']);
            $item = $poItem->item;
            $this->qtyCategoryValidator->assertValid($item, $line['qty']);
            $qty = $this->qtyCategoryValidator->round($item, $line['qty']);
            $amount = $qty * $poItem->rate;
            [$taxId, $taxAmount] = $this->taxService->resolveLineTax(['tax_id' => $poItem->tax_id], null, '', $amount);

            $this->goodsReceiptItemRepository->create([
                'goods_receipt_id' => $goodsReceipt->id,
                'purchase_order_item_id' => $poItem->id,
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'uom' => $item->uom->name,
                'qty' => $qty,
                'over_receipt_qty' => $overReceiptByIndex[$index] ?? 0,
                'qty_category' => $item->qty_category,
                'rate' => $poItem->rate,
                'amount' => $amount,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
            ]);
        }
    }

    public function update(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($goodsReceipt, $data) {
            if ($goodsReceipt->status === DocumentStatus::SUBMITTED) {
                return $this->updateSubmitted($goodsReceipt, $data);
            }

            $this->assertDraft($goodsReceipt, 'updated');

            $headerData = collect($data)->except('items')->all();
            $headerData['due_date'] = $this->resolveDueDate($data, $goodsReceipt->supplier_id, $data['receipt_date'] ?? $goodsReceipt->receipt_date);

            if (isset($data['items'])) {
                $goodsReceipt->items()->delete();
                $this->replaceItems($goodsReceipt, $data['items'], (bool) ($data['confirm_over_receipt'] ?? false));
            }

            $this->goodsReceiptRepository->update($goodsReceipt, $headerData);

            $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'purchaseOrder', 'items']);
            $this->auditLogService->record('updated', 'goods_receipt', "Updated Goods Receipt \"{$goodsReceipt->document_number}\".");

            return $goodsReceipt;
        });
    }

    /**
     * A confirmed receipt can be corrected in full — items/qty/rate/warehouse, and (Direct
     * Receipt only) supplier — because a wrong item, qty or supplier is a data-entry mistake,
     * not a new transaction. Stock already moved at submit(), so an items/warehouse change
     * first reverses exactly what was posted (reverseReceiptStock(), which fails loudly via
     * FifoLayerService::reverseReceipt() if any of this receipt's FIFO layers has already been
     * consumed by a later Sale/Issue — the same hard rule OpeningStockService::cancel() relies
     * on; that consumed stock has already flowed into another document's cost, so it can't be
     * rewritten out from under it) before reposting fresh stock for the new lines. A
     * header-only edit (dates/notes, same items/warehouse) skips the reverse/repost — the FIFO
     * layer's received_date still needs to follow a receipt_date change either way.
     */
    protected function updateSubmitted(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt
    {
        $itemsChanging = array_key_exists('items', $data);
        $warehouseChanging = array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== $goodsReceipt->warehouse_id;

        if ($itemsChanging || $warehouseChanging) {
            $goodsReceipt->load('items.purchaseOrderItem');
            $this->reverseReceiptStock($goodsReceipt);

            if ($itemsChanging) {
                $goodsReceipt->items()->delete();
            }
        }

        $receiptDate = $data['receipt_date'] ?? $goodsReceipt->receipt_date->toDateString();
        $supplierId = ($goodsReceipt->purchase_order_id === null && ! empty($data['supplier_id']))
            ? $data['supplier_id']
            : $goodsReceipt->supplier_id;

        $this->goodsReceiptRepository->update($goodsReceipt, [
            'supplier_id' => $supplierId,
            'warehouse_id' => $data['warehouse_id'] ?? $goodsReceipt->warehouse_id,
            'receipt_date' => $receiptDate,
            'due_date' => $this->resolveDueDate($data, $supplierId, $receiptDate),
            'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $goodsReceipt->remarks,
        ]);

        if ($itemsChanging) {
            $this->replaceItems($goodsReceipt, $data['items'], (bool) ($data['confirm_over_receipt'] ?? false));
        }

        if ($itemsChanging || $warehouseChanging) {
            $goodsReceipt->load('items.purchaseOrderItem.item');
            $this->postReceiptStock($goodsReceipt);
        }

        FifoLayer::query()
            ->where('source_type', StockVoucherType::GOODS_RECEIPT->value)
            ->where('source_id', $goodsReceipt->id)
            ->update(['received_date' => $receiptDate]);

        $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'purchaseOrder', 'items']);
        $this->auditLogService->record('updated', 'goods_receipt', "Updated confirmed Goods Receipt \"{$goodsReceipt->document_number}\".");

        return $goodsReceipt;
    }

    /**
     * Reverses exactly what postReceiptStock() posted for $goodsReceipt's *current* items —
     * deletes their FIFO layers (rejects if any has already been consumed downstream), posts a
     * matching negative Stock Ledger entry per line, and reverses the PO received_qty
     * increment. Must run before the receipt's warehouse_id or items are changed, since it
     * relies on both still pointing at what was actually posted at submit() time.
     */
    protected function reverseReceiptStock(GoodsReceipt $goodsReceipt): void
    {
        $this->fifoLayerService->reverseReceipt(StockVoucherType::GOODS_RECEIPT, $goodsReceipt->id);

        foreach ($goodsReceipt->items as $line) {
            $this->stockLedgerService->record(
                itemId: $line->item_id,
                warehouseId: $goodsReceipt->warehouse_id,
                transactionType: StockTransactionType::OUT,
                voucherType: StockVoucherType::GOODS_RECEIPT,
                voucherId: $goodsReceipt->id,
                qtyChange: -(float) $line->qty,
                postingDatetime: $goodsReceipt->receipt_date,
                referenceNo: $goodsReceipt->document_number,
                remarks: "Correction of Goods Receipt {$goodsReceipt->document_number}",
            );

            if ($line->purchaseOrderItem !== null) {
                $this->purchaseOrderItemRepository->incrementReceivedQty($line->purchaseOrderItem, -(float) $line->qty);
            }
        }
    }

    /**
     * Terms of Payment live on the Supplier, not the receipt — due date is receipt_date + the
     * Supplier's TOP days (no TOP = due on receipt). An explicit due_date still wins (historical
     * import passes one).
     */
    protected function resolveDueDate(array $data, string $supplierId, $receiptDate): string
    {
        if (! empty($data['due_date'])) {
            return $data['due_date'];
        }

        $days = Supplier::query()->with('termsOfPayment')->find($supplierId)?->termsOfPayment?->days ?? 0;

        return Carbon::parse($receiptDate)->addDays($days)->toDateString();
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

                // Weight-category groups are skipped here — already validated (and, if needed,
                // confirmed) against the tolerance setting at create()/update() time, and unlike
                // Unit they're never hard-blocked by outstanding qty at all.
                $goodsReceipt->items
                    ->groupBy('purchase_order_item_id')
                    ->each(function ($group) {
                        $poItem = $group->first()->purchaseOrderItem;

                        if ($poItem->item->qty_category === QtyCategory::UNIT) {
                            $this->assertWithinOutstanding($poItem, (float) $group->sum('qty'));
                        }
                    });
            }

            $this->postReceiptStock($goodsReceipt);

            $goodsReceipt->submit();

            $goodsReceipt = $goodsReceipt->fresh(['supplier', 'warehouse', 'purchaseOrder', 'items']);
            $this->auditLogService->record('submitted', 'goods_receipt', "Submitted Goods Receipt \"{$goodsReceipt->document_number}\".");

            return $goodsReceipt;
        });
    }

    /**
     * Posts stock for $goodsReceipt's *current* items — Stock Ledger IN entries, FIFO
     * receive() layers, and PO received_qty increments. Shared by submit() (first time) and
     * updateSubmitted() (repost after reverseReceiptStock()); PO status/outstanding validation
     * stays scoped to submit() — updateSubmitted() already re-validates outstanding via
     * replaceItems()'s assertAggregateWithinOutstanding() call before reaching here.
     */
    protected function postReceiptStock(GoodsReceipt $goodsReceipt): void
    {
        foreach ($goodsReceipt->items as $line) {
            $this->stockLedgerService->record(
                itemId: $line->item_id,
                warehouseId: $goodsReceipt->warehouse_id,
                transactionType: StockTransactionType::IN,
                voucherType: StockVoucherType::GOODS_RECEIPT,
                voucherId: $goodsReceipt->id,
                qtyChange: $line->qty,
                postingDatetime: $goodsReceipt->receipt_date,
                referenceNo: $goodsReceipt->document_number,
                remarks: "Goods Receipt {$goodsReceipt->document_number}",
            );

            $this->fifoLayerService->receive(
                itemId: $line->item_id,
                warehouseId: $goodsReceipt->warehouse_id,
                qty: (float) $line->qty,
                unitCost: (float) $line->rate,
                sourceType: StockVoucherType::GOODS_RECEIPT,
                sourceId: $goodsReceipt->id,
                sourceDocumentNumber: $goodsReceipt->document_number,
                receivedDate: $goodsReceipt->receipt_date,
            );

            if ($line->purchaseOrderItem !== null) {
                $this->purchaseOrderItemRepository->incrementReceivedQty($line->purchaseOrderItem, $line->qty);
            }
        }
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
     * against that item's outstanding qty. Unit-category items keep the
     * existing hard block (Item.allow_over_receipt opt-in bypass); Weight-
     * category items are never hard-blocked here — over/under vs. the PO's
     * ordered qty is normal for a truck-scale result, only gated by the
     * configured tolerance (see QtyCategoryValidator::assertWeightOverReceiptAllowed).
     */
    protected function assertAggregateWithinOutstanding(string $purchaseOrderId, array $lines, bool $confirmOverReceipt): void
    {
        $totalsByPoItemId = collect($lines)
            ->groupBy('purchase_order_item_id')
            ->map(fn ($group) => collect($group)->sum('qty'));

        foreach ($totalsByPoItemId as $purchaseOrderItemId => $totalQty) {
            $poItem = $this->resolvePurchaseOrderItem($purchaseOrderId, $purchaseOrderItemId);
            $item = $poItem->item;
            $outstanding = (float) ($poItem->qty - $poItem->received_qty);

            if ($item->qty_category === QtyCategory::WEIGHT) {
                $this->qtyCategoryValidator->assertWeightOverReceiptAllowed($item, $outstanding, (float) $totalQty, $confirmOverReceipt);
            } else {
                $this->assertWithinOutstanding($poItem, (float) $totalQty);
            }
        }
    }

    protected function assertWithinOutstanding(PurchaseOrderItem $poItem, int|float $qty): void
    {
        $outstanding = $poItem->qty - $poItem->received_qty;

        if ($qty > $outstanding && ! $poItem->item->allow_over_receipt) {
            throw new BusinessException("Received qty ({$qty}) exceeds outstanding qty ({$outstanding}) for item {$poItem->item->item_code}.");
        }
    }

    /**
     * Attributes the excess-over-outstanding qty per line (not just per PO
     * item) for report traceability — GoodsReceiptItem.over_receipt_qty. When
     * the same PO item spans multiple lines, only the portion of each line's
     * own qty that pushed the running total past outstanding counts as that
     * line's over-receipt, in the order the lines were submitted. Applies
     * regardless of category — a Unit item over-received via the
     * allow_over_receipt flag gets the same traceability as a Weight item.
     *
     * @return array<int, float> line index (as given in $lines) => over_receipt_qty
     */
    protected function computeOverReceiptQtyByIndex(string $purchaseOrderId, array $lines): array
    {
        $overReceiptByIndex = [];

        collect($lines)
            ->map(fn ($line, $index) => ['index' => $index, ...$line])
            ->groupBy('purchase_order_item_id')
            ->each(function ($group, $purchaseOrderItemId) use ($purchaseOrderId, &$overReceiptByIndex) {
                $poItem = $this->resolvePurchaseOrderItem($purchaseOrderId, $purchaseOrderItemId);
                $outstanding = (float) ($poItem->qty - $poItem->received_qty);
                $running = 0.0;

                foreach ($group as $entry) {
                    $before = $running;
                    $running += (float) $entry['qty'];
                    $overReceiptByIndex[$entry['index']] = max(0.0, $running - $outstanding) - max(0.0, $before - $outstanding);
                }
            });

        return $overReceiptByIndex;
    }

    protected function assertDraft(GoodsReceipt $goodsReceipt, string $action): void
    {
        if ($goodsReceipt->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Goods Receipts can be {$action}.");
        }
    }
}
