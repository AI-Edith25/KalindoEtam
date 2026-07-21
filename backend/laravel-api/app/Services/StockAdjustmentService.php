<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\StockAdjustment;
use App\Repositories\ItemRepository;
use App\Repositories\StockAdjustmentItemRepository;
use App\Repositories\StockAdjustmentRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    public function __construct(
        protected StockAdjustmentRepository $stockAdjustmentRepository,
        protected StockAdjustmentItemRepository $stockAdjustmentItemRepository,
        protected ItemRepository $itemRepository,
        protected StockLedgerService $stockLedgerService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->stockAdjustmentRepository->search($filters, $perPage);
    }

    public function create(array $data): StockAdjustment
    {
        return DB::transaction(function () use ($data) {
            $adjustment = $this->stockAdjustmentRepository->create([
                'warehouse_id' => $data['warehouse_id'],
                'adjustment_date' => $data['adjustment_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->replaceItems($adjustment, $data['items']);

            $adjustment = $adjustment->fresh(['warehouse', 'items']);
            $this->auditLogService->record('created', 'stock', "Created Stock Adjustment \"{$adjustment->document_number}\".");

            return $adjustment;
        });
    }

    public function update(StockAdjustment $adjustment, array $data): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $data) {
            $this->assertDraft($adjustment, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $this->replaceItems($adjustment, $data['items']);
            }

            $this->stockAdjustmentRepository->update($adjustment, $headerData);

            $adjustment = $adjustment->fresh(['warehouse', 'items']);
            $this->auditLogService->record('updated', 'stock', "Updated Stock Adjustment \"{$adjustment->document_number}\".");

            return $adjustment;
        });
    }

    public function delete(StockAdjustment $adjustment): void
    {
        DB::transaction(function () use ($adjustment) {
            $this->assertDraft($adjustment, 'deleted');
            $documentNumber = $adjustment->document_number;
            $this->stockAdjustmentRepository->delete($adjustment);
            $this->auditLogService->record('deleted', 'stock', "Deleted Stock Adjustment \"{$documentNumber}\".");
        });
    }

    /**
     * Reconciles physical count to the ledger for each line via
     * StockLedgerService::recordToBalance() — the only method in this
     * service that ever touches stock. The system_qty/difference_qty
     * already stored on each line (from create/update time) are display
     * snapshots only; the actual ledger write is always driven by a fresh,
     * locked read taken here at submit time, so the result is correct even
     * if stock moved for other reasons since the draft was last saved.
     */
    public function submit(StockAdjustment $adjustment): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment) {
            $adjustment->load('items');

            if ($adjustment->items->count() === 0) {
                throw new BusinessException('Cannot submit a Stock Adjustment without items.');
            }

            foreach ($adjustment->items as $line) {
                $this->stockLedgerService->recordToBalance(
                    itemId: $line->item_id,
                    warehouseId: $adjustment->warehouse_id,
                    targetBalance: $line->counted_qty,
                    transactionType: StockTransactionType::ADJUSTMENT,
                    voucherType: StockVoucherType::STOCK_ADJUSTMENT,
                    voucherId: $adjustment->id,
                    postingDatetime: now(),
                    referenceNo: $adjustment->document_number,
                    remarks: $line->reason,
                );
            }

            $adjustment->submit();

            $adjustment = $adjustment->fresh(['warehouse', 'items']);
            $this->auditLogService->record('submitted', 'stock', "Submitted Stock Adjustment \"{$adjustment->document_number}\".");

            return $adjustment;
        });
    }

    protected function replaceItems(StockAdjustment $adjustment, array $items): void
    {
        $adjustment->items()->delete();

        foreach ($items as $line) {
            $item = $this->itemRepository->findOrFail($line['item_id']);
            $systemQty = $this->stockLedgerService->peekBalance($item->id, $adjustment->warehouse_id);

            $this->stockAdjustmentItemRepository->create([
                'stock_adjustment_id' => $adjustment->id,
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'uom' => $item->uom->name,
                'system_qty' => $systemQty,
                'counted_qty' => $line['counted_qty'],
                'difference_qty' => $line['counted_qty'] - $systemQty,
                'reason' => $line['reason'],
            ]);
        }
    }

    protected function assertDraft(StockAdjustment $adjustment, string $action): void
    {
        if ($adjustment->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Stock Adjustments can be {$action}.");
        }
    }
}
