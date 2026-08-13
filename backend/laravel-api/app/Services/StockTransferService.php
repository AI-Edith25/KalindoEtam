<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\StockTransfer;
use App\Repositories\ItemRepository;
use App\Repositories\StockTransferItemRepository;
use App\Repositories\StockTransferRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(
        protected StockTransferRepository $stockTransferRepository,
        protected StockTransferItemRepository $stockTransferItemRepository,
        protected ItemRepository $itemRepository,
        protected StockLedgerService $stockLedgerService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->stockTransferRepository->search($filters, $perPage);
    }

    public function create(array $data): StockTransfer
    {
        return DB::transaction(function () use ($data) {
            $this->assertDifferentWarehouses($data['source_warehouse_id'], $data['destination_warehouse_id']);

            $transfer = $this->stockTransferRepository->create([
                'source_warehouse_id' => $data['source_warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'transfer_date' => $data['transfer_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->replaceItems($transfer, $data['items']);

            $transfer = $transfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'items']);
            $this->auditLogService->record('created', 'stock', "Created Stock Transfer \"{$transfer->document_number}\".");

            return $transfer;
        });
    }

    public function update(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data) {
            $this->assertDraft($transfer, 'updated');

            $this->assertDifferentWarehouses(
                $data['source_warehouse_id'] ?? $transfer->source_warehouse_id,
                $data['destination_warehouse_id'] ?? $transfer->destination_warehouse_id,
            );

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $this->replaceItems($transfer, $data['items']);
            }

            $this->stockTransferRepository->update($transfer, $headerData);

            $transfer = $transfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'items']);
            $this->auditLogService->record('updated', 'stock', "Updated Stock Transfer \"{$transfer->document_number}\".");

            return $transfer;
        });
    }

    public function delete(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $this->assertDraft($transfer, 'deleted');
            $documentNumber = $transfer->document_number;
            $this->stockTransferRepository->delete($transfer);
            $this->auditLogService->record('deleted', 'stock', "Deleted Stock Transfer \"{$documentNumber}\".");
        });
    }

    /**
     * Direct-effect transfer: no in-transit status. For each line, validate
     * available stock at the source warehouse, then move it out of source
     * and into destination via two StockLedgerService::record() calls — the
     * same single gateway every other stock-moving document already uses.
     * No AccountingService call: this Chart of Accounts has a single global
     * Inventory account, not one per warehouse, so a debit/credit pair
     * against the same account would be a self-canceling no-op (confirmed
     * with the user before building this — see the Stock Transfer plan).
     */
    public function submit(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer->load('items');

            if ($transfer->items->count() === 0) {
                throw new BusinessException('Cannot submit a Stock Transfer without items.');
            }

            foreach ($transfer->items as $line) {
                $this->assertSufficientStock($transfer->source_warehouse_id, $line->item_id, $line->qty);
            }

            foreach ($transfer->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $transfer->source_warehouse_id,
                    transactionType: StockTransactionType::OUT,
                    voucherType: StockVoucherType::STOCK_TRANSFER,
                    voucherId: $transfer->id,
                    qtyChange: -$line->qty,
                    postingDatetime: now(),
                    referenceNo: $transfer->document_number,
                    remarks: "Transfer out to {$transfer->destinationWarehouse->name} ({$transfer->document_number})",
                );

                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $transfer->destination_warehouse_id,
                    transactionType: StockTransactionType::IN,
                    voucherType: StockVoucherType::STOCK_TRANSFER,
                    voucherId: $transfer->id,
                    qtyChange: $line->qty,
                    postingDatetime: now(),
                    referenceNo: $transfer->document_number,
                    remarks: "Transfer in from {$transfer->sourceWarehouse->name} ({$transfer->document_number})",
                );
            }

            $transfer->submit();

            $transfer = $transfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'items']);
            $this->auditLogService->record('submitted', 'stock', "Submitted Stock Transfer \"{$transfer->document_number}\".");

            return $transfer;
        });
    }

    protected function replaceItems(StockTransfer $transfer, array $items): void
    {
        $transfer->items()->delete();

        foreach ($items as $line) {
            $item = $this->itemRepository->findOrFail($line['item_id']);

            $this->stockTransferItemRepository->create([
                'stock_transfer_id' => $transfer->id,
                'item_id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'uom' => $item->uom->name,
                'qty' => $line['qty'],
            ]);
        }
    }

    protected function assertDifferentWarehouses(string $sourceWarehouseId, string $destinationWarehouseId): void
    {
        if ($sourceWarehouseId === $destinationWarehouseId) {
            throw new BusinessException('Source and destination warehouse must be different.');
        }
    }

    protected function assertSufficientStock(string $warehouseId, string $itemId, int $qty): void
    {
        $available = $this->stockLedgerService->getCurrentBalance($itemId, $warehouseId);

        if ($qty > $available) {
            throw new BusinessException("Insufficient stock: requested {$qty}, available {$available} in the source warehouse.");
        }
    }

    protected function assertDraft(StockTransfer $transfer, string $action): void
    {
        if ($transfer->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Stock Transfers can be {$action}.");
        }
    }
}
