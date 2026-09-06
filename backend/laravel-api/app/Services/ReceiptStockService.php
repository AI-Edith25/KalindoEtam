<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\ReceiptStock;
use App\Repositories\ItemRepository;
use App\Repositories\ReceiptStockItemRepository;
use App\Repositories\ReceiptStockRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Receipt Stock — stock entering the warehouse outside of a purchase (returns
 * from usage, stock found, supplier gifts). Mirrors OpeningStockService
 * almost exactly: submit() creates a new FIFO layer per line at the
 * user-entered cost, cancel() reverses it via reverseReceipt() (blocked once
 * consumed, same rule).
 */
class ReceiptStockService
{
    protected const EAGER = ['warehouse', 'items'];

    public function __construct(
        protected ReceiptStockRepository $receiptStockRepository,
        protected ReceiptStockItemRepository $receiptStockItemRepository,
        protected ItemRepository $itemRepository,
        protected StockLedgerService $stockLedgerService,
        protected FifoLayerService $fifoLayerService,
        protected AuditLogService $auditLogService,
        protected QtyCategoryValidator $qtyCategoryValidator,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->receiptStockRepository->search($filters, $perPage);
    }

    public function find(string $id): ReceiptStock
    {
        return $this->receiptStockRepository->findOrFail($id);
    }

    public function create(array $data): ReceiptStock
    {
        return DB::transaction(function () use ($data) {
            $receiptStock = $this->receiptStockRepository->create([
                'warehouse_id' => $data['warehouse_id'],
                'receipt_date' => $data['receipt_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->replaceItems($receiptStock, $data['items']);

            $receiptStock = $receiptStock->fresh(self::EAGER);
            $this->auditLogService->record('created', 'receipt_stock', "Created Receipt Stock \"{$receiptStock->document_number}\".");

            return $receiptStock;
        });
    }

    public function update(ReceiptStock $receiptStock, array $data): ReceiptStock
    {
        return DB::transaction(function () use ($receiptStock, $data) {
            $this->assertDraft($receiptStock, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $this->replaceItems($receiptStock, $data['items']);
            }

            $this->receiptStockRepository->update($receiptStock, $headerData);

            $receiptStock = $receiptStock->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'receipt_stock', "Updated Receipt Stock \"{$receiptStock->document_number}\".");

            return $receiptStock;
        });
    }

    public function delete(ReceiptStock $receiptStock): void
    {
        DB::transaction(function () use ($receiptStock) {
            $this->assertDraft($receiptStock, 'deleted');
            $documentNumber = $receiptStock->document_number;
            $this->receiptStockRepository->delete($receiptStock);
            $this->auditLogService->record('deleted', 'receipt_stock', "Deleted Receipt Stock \"{$documentNumber}\".");
        });
    }

    public function submit(ReceiptStock $receiptStock): ReceiptStock
    {
        return DB::transaction(function () use ($receiptStock) {
            $receiptStock->load('items');

            if ($receiptStock->items->count() === 0) {
                throw new BusinessException('Cannot submit a Receipt Stock without items.');
            }

            foreach ($receiptStock->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $receiptStock->warehouse_id,
                    transactionType: StockTransactionType::IN,
                    voucherType: StockVoucherType::RECEIPT_STOCK,
                    voucherId: $receiptStock->id,
                    qtyChange: (float) $line->qty,
                    // now(), not receipt_date — same convention as GoodsReceipt/Delivery (see
                    // IssueStockService::submit()'s identical note).
                    postingDatetime: now(),
                    referenceNo: $receiptStock->document_number,
                    remarks: "Receipt Stock {$receiptStock->document_number}",
                );

                $this->fifoLayerService->receive(
                    itemId: $line->item_id,
                    warehouseId: $receiptStock->warehouse_id,
                    qty: (float) $line->qty,
                    unitCost: (float) $line->unit_cost,
                    sourceType: StockVoucherType::RECEIPT_STOCK,
                    sourceId: $receiptStock->id,
                    sourceDocumentNumber: $receiptStock->document_number,
                    receivedDate: $receiptStock->receipt_date,
                );
            }

            $receiptStock->submit();

            $receiptStock = $receiptStock->fresh(self::EAGER);
            $this->auditLogService->record('submitted', 'receipt_stock', "Submitted Receipt Stock \"{$receiptStock->document_number}\".");

            return $receiptStock;
        });
    }

    public function cancel(ReceiptStock $receiptStock): ReceiptStock
    {
        return DB::transaction(function () use ($receiptStock) {
            $receiptStock->load('items');

            $this->fifoLayerService->reverseReceipt(StockVoucherType::RECEIPT_STOCK, $receiptStock->id);

            foreach ($receiptStock->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $receiptStock->warehouse_id,
                    transactionType: StockTransactionType::OUT,
                    voucherType: StockVoucherType::RECEIPT_STOCK,
                    voucherId: $receiptStock->id,
                    qtyChange: -(float) $line->qty,
                    postingDatetime: now(),
                    referenceNo: $receiptStock->document_number,
                    remarks: "Cancellation of Receipt Stock {$receiptStock->document_number}",
                );
            }

            $receiptStock->cancel();

            $receiptStock = $receiptStock->fresh(self::EAGER);
            $this->auditLogService->record('cancelled', 'receipt_stock', "Cancelled Receipt Stock \"{$receiptStock->document_number}\".");

            return $receiptStock;
        });
    }

    protected function replaceItems(ReceiptStock $receiptStock, array $items): void
    {
        $receiptStock->items()->delete();

        foreach ($items as $line) {
            $item = $this->itemRepository->findOrFail($line['item_id']);
            $this->qtyCategoryValidator->assertValid($item, $line['qty']);
            $qty = $this->qtyCategoryValidator->round($item, $line['qty']);

            if ((float) $line['unit_cost'] < 0) {
                throw new BusinessException("Unit Cost cannot be negative for item {$item->item_code}.");
            }

            $this->receiptStockItemRepository->create([
                'receipt_stock_id' => $receiptStock->id,
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'uom' => $item->uom->name,
                'qty_category' => $item->qty_category,
                'qty' => $qty,
                'unit_cost' => $line['unit_cost'],
                'amount' => round($qty * $line['unit_cost'], 2),
            ]);
        }
    }

    protected function assertDraft(ReceiptStock $receiptStock, string $action): void
    {
        if ($receiptStock->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Receipt Stocks can be {$action}.");
        }
    }
}
