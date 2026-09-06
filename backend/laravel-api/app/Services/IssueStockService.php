<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\IssueStock;
use App\Repositories\IssueStockItemRepository;
use App\Repositories\IssueStockRepository;
use App\Repositories\ItemRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Issue Stock — stock leaving the warehouse outside of a sale (internal use,
 * damage, samples). Mirrors OpeningStockService's Draft -> Submitted ->
 * Cancelled lifecycle, but moving OUT: submit() consumes FIFO layers
 * oldest-first (FifoLayerService::consume() itself rejects an over-limit
 * qty — the ticket's "tolak Submit kalau qty melebihi stok tersedia"), cancel()
 * restores the exact original layers via reverseConsumption().
 */
class IssueStockService
{
    protected const EAGER = ['warehouse', 'items'];

    public function __construct(
        protected IssueStockRepository $issueStockRepository,
        protected IssueStockItemRepository $issueStockItemRepository,
        protected ItemRepository $itemRepository,
        protected StockLedgerService $stockLedgerService,
        protected FifoLayerService $fifoLayerService,
        protected AuditLogService $auditLogService,
        protected QtyCategoryValidator $qtyCategoryValidator,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->issueStockRepository->search($filters, $perPage);
    }

    public function find(string $id): IssueStock
    {
        return $this->issueStockRepository->findOrFail($id);
    }

    public function create(array $data): IssueStock
    {
        return DB::transaction(function () use ($data) {
            $issueStock = $this->issueStockRepository->create([
                'warehouse_id' => $data['warehouse_id'],
                'issue_date' => $data['issue_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->replaceItems($issueStock, $data['items']);

            $issueStock = $issueStock->fresh(self::EAGER);
            $this->auditLogService->record('created', 'issue_stock', "Created Issue Stock \"{$issueStock->document_number}\".");

            return $issueStock;
        });
    }

    public function update(IssueStock $issueStock, array $data): IssueStock
    {
        return DB::transaction(function () use ($issueStock, $data) {
            $this->assertDraft($issueStock, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $this->replaceItems($issueStock, $data['items']);
            }

            $this->issueStockRepository->update($issueStock, $headerData);

            $issueStock = $issueStock->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'issue_stock', "Updated Issue Stock \"{$issueStock->document_number}\".");

            return $issueStock;
        });
    }

    public function delete(IssueStock $issueStock): void
    {
        DB::transaction(function () use ($issueStock) {
            $this->assertDraft($issueStock, 'deleted');
            $documentNumber = $issueStock->document_number;
            $this->issueStockRepository->delete($issueStock);
            $this->auditLogService->record('deleted', 'issue_stock', "Deleted Issue Stock \"{$documentNumber}\".");
        });
    }

    /**
     * Consumes FIFO layers oldest-first per line and writes the resulting weighted-average
     * cost back onto the line (unit_cost/amount are null until here — the form only ever
     * showed a live preview, never a user-typed value).
     */
    public function submit(IssueStock $issueStock): IssueStock
    {
        return DB::transaction(function () use ($issueStock) {
            $issueStock->load('items');

            if ($issueStock->items->count() === 0) {
                throw new BusinessException('Cannot submit an Issue Stock without items.');
            }

            foreach ($issueStock->items as $line) {
                $result = $this->fifoLayerService->consume(
                    itemId: $line->item_id,
                    warehouseId: $issueStock->warehouse_id,
                    qty: (float) $line->qty,
                    sourceType: StockVoucherType::ISSUE_STOCK,
                    sourceId: $issueStock->id,
                );

                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $issueStock->warehouse_id,
                    transactionType: StockTransactionType::OUT,
                    voucherType: StockVoucherType::ISSUE_STOCK,
                    voucherId: $issueStock->id,
                    qtyChange: -(float) $line->qty,
                    // now(), not issue_date — same convention as Delivery/GoodsReceipt: the ledger
                    // orders by real submission time so "current balance" is always the latest
                    // physical state, regardless of what business date the document is labeled with.
                    postingDatetime: now(),
                    referenceNo: $issueStock->document_number,
                    remarks: "Issue Stock {$issueStock->document_number}",
                );

                $line->update([
                    'unit_cost' => $result->weightedAverageUnitCost,
                    'amount' => round((float) $line->qty * $result->weightedAverageUnitCost, 2),
                ]);
            }

            $issueStock->submit();

            $issueStock = $issueStock->fresh(self::EAGER);
            $this->auditLogService->record('submitted', 'issue_stock', "Submitted Issue Stock \"{$issueStock->document_number}\".");

            return $issueStock;
        });
    }

    /**
     * Restores the exact original layers this document consumed (reverseConsumption(), not a
     * new averaged layer) and posts the offsetting IN ledger entry — mirrors
     * OpeningStockService::cancel()'s reversing-entry pattern, opposite direction.
     */
    public function cancel(IssueStock $issueStock): IssueStock
    {
        return DB::transaction(function () use ($issueStock) {
            $issueStock->load('items');

            $this->fifoLayerService->reverseConsumption(StockVoucherType::ISSUE_STOCK, $issueStock->id);

            foreach ($issueStock->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $issueStock->warehouse_id,
                    transactionType: StockTransactionType::IN,
                    voucherType: StockVoucherType::ISSUE_STOCK,
                    voucherId: $issueStock->id,
                    qtyChange: (float) $line->qty,
                    postingDatetime: now(),
                    referenceNo: $issueStock->document_number,
                    remarks: "Cancellation of Issue Stock {$issueStock->document_number}",
                );
            }

            $issueStock->cancel();

            $issueStock = $issueStock->fresh(self::EAGER);
            $this->auditLogService->record('cancelled', 'issue_stock', "Cancelled Issue Stock \"{$issueStock->document_number}\".");

            return $issueStock;
        });
    }

    /** Live FIFO-computed preview for the editor's read-only Unit Cost column — see FifoLayerService::previewConsumption(). */
    public function previewCost(string $itemId, string $warehouseId, float $qty): array
    {
        return $this->fifoLayerService->previewConsumption($itemId, $warehouseId, $qty);
    }

    protected function replaceItems(IssueStock $issueStock, array $items): void
    {
        $issueStock->items()->delete();

        foreach ($items as $line) {
            $item = $this->itemRepository->findOrFail($line['item_id']);
            $this->qtyCategoryValidator->assertValid($item, $line['qty']);
            $qty = $this->qtyCategoryValidator->round($item, $line['qty']);

            $this->issueStockItemRepository->create([
                'issue_stock_id' => $issueStock->id,
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'uom' => $item->uom->name,
                'qty_category' => $item->qty_category,
                'qty' => $qty,
            ]);
        }
    }

    protected function assertDraft(IssueStock $issueStock, string $action): void
    {
        if ($issueStock->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Issue Stocks can be {$action}.");
        }
    }
}
