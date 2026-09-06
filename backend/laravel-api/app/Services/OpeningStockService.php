<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\FifoLayer;
use App\Models\OpeningStock;
use App\Repositories\ItemRepository;
use App\Repositories\OpeningStockItemRepository;
use App\Repositories\OpeningStockRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opening Stock — an item's starting balance + cost, entered separately from
 * any purchase document (so it never pollutes purchase reports). Standard
 * Draft -> Submitted -> Cancelled lifecycle (Documentable's default): unlike
 * every other stock document in this app, cancel() actually works here —
 * see cancel() below and FifoLayerService::reverseReceipt() (blocked once
 * the layer has been consumed, same rule the ticket asks for).
 */
class OpeningStockService
{
    protected const EAGER = ['warehouse', 'items'];

    public function __construct(
        protected OpeningStockRepository $openingStockRepository,
        protected OpeningStockItemRepository $openingStockItemRepository,
        protected ItemRepository $itemRepository,
        protected StockLedgerService $stockLedgerService,
        protected FifoLayerService $fifoLayerService,
        protected AuditLogService $auditLogService,
        protected QtyCategoryValidator $qtyCategoryValidator,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->openingStockRepository->search($filters, $perPage);
    }

    public function listAll(array $filters = []): Collection
    {
        return $this->openingStockRepository->search($filters, PHP_INT_MAX)->getCollection();
    }

    public function find(string $id): OpeningStock
    {
        return $this->openingStockRepository->findOrFail($id);
    }

    public function create(array $data): OpeningStock
    {
        return DB::transaction(function () use ($data) {
            $openingStock = $this->openingStockRepository->create([
                'warehouse_id' => $data['warehouse_id'],
                'cutoff_date' => $data['cutoff_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->replaceItems($openingStock, $data['items']);

            $openingStock = $openingStock->fresh(self::EAGER);
            $this->auditLogService->record('created', 'opening_stock', "Created Opening Stock \"{$openingStock->document_number}\".");

            return $openingStock;
        });
    }

    public function update(OpeningStock $openingStock, array $data): OpeningStock
    {
        return DB::transaction(function () use ($openingStock, $data) {
            $this->assertDraft($openingStock, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $this->replaceItems($openingStock, $data['items']);
            }

            $this->openingStockRepository->update($openingStock, $headerData);

            $openingStock = $openingStock->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'opening_stock', "Updated Opening Stock \"{$openingStock->document_number}\".");

            return $openingStock;
        });
    }

    public function delete(OpeningStock $openingStock): void
    {
        DB::transaction(function () use ($openingStock) {
            $this->assertDraft($openingStock, 'deleted');
            $documentNumber = $openingStock->document_number;
            $this->openingStockRepository->delete($openingStock);
            $this->auditLogService->record('deleted', 'opening_stock', "Deleted Opening Stock \"{$documentNumber}\".");
        });
    }

    /**
     * Layers only form here, one per line (never merged, even for the same item at different
     * costs — that's the whole point of FIFO, see the ticket). received_date is the document's
     * own cutoff_date, not "now" — an opening stock has to sort before whatever real activity
     * follows it.
     */
    public function submit(OpeningStock $openingStock): OpeningStock
    {
        return DB::transaction(function () use ($openingStock) {
            $openingStock->load('items');

            if ($openingStock->items->count() === 0) {
                throw new BusinessException('Cannot submit an Opening Stock without items.');
            }

            foreach ($openingStock->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $openingStock->warehouse_id,
                    transactionType: StockTransactionType::IN,
                    voucherType: StockVoucherType::OPENING_STOCK,
                    voucherId: $openingStock->id,
                    qtyChange: (float) $line->qty,
                    postingDatetime: $openingStock->cutoff_date,
                    referenceNo: $openingStock->document_number,
                    remarks: "Opening Stock {$openingStock->document_number}",
                );

                $this->fifoLayerService->receive(
                    itemId: $line->item_id,
                    warehouseId: $openingStock->warehouse_id,
                    qty: (float) $line->qty,
                    unitCost: (float) $line->unit_cost,
                    sourceType: StockVoucherType::OPENING_STOCK,
                    sourceId: $openingStock->id,
                    sourceDocumentNumber: $openingStock->document_number,
                    receivedDate: $openingStock->cutoff_date,
                );
            }

            $openingStock->submit();

            $openingStock = $openingStock->fresh(self::EAGER);
            $this->auditLogService->record('submitted', 'opening_stock', "Submitted Opening Stock \"{$openingStock->document_number}\".");

            return $openingStock;
        });
    }

    /**
     * Reverses every layer this document created (FifoLayerService::reverseReceipt() itself
     * blocks this — throws — the moment any of them has been partially or fully consumed,
     * satisfying the ticket's "ditolak bila layer-nya sudah terkonsumsi sebagian") and posts
     * the offsetting OUT ledger entry — mirrors PurchaseReturnService/CreditNoteService's
     * reverse pattern, just riding the standard Documentable::cancel() status flip instead of
     * a bespoke is_reversed flag (Opening Stock has no separate "reversed" state; Cancelled
     * already is the terminal state, matching every other document's Draft/Submitted/Cancelled
     * lifecycle).
     */
    public function cancel(OpeningStock $openingStock): OpeningStock
    {
        return DB::transaction(function () use ($openingStock) {
            $openingStock->load('items');

            $this->fifoLayerService->reverseReceipt(StockVoucherType::OPENING_STOCK, $openingStock->id);

            foreach ($openingStock->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $openingStock->warehouse_id,
                    transactionType: StockTransactionType::OUT,
                    voucherType: StockVoucherType::OPENING_STOCK,
                    voucherId: $openingStock->id,
                    qtyChange: -(float) $line->qty,
                    postingDatetime: now(),
                    referenceNo: $openingStock->document_number,
                    remarks: "Cancellation of Opening Stock {$openingStock->document_number}",
                );
            }

            $openingStock->cancel();

            $openingStock = $openingStock->fresh(self::EAGER);
            $this->auditLogService->record('cancelled', 'opening_stock', "Cancelled Opening Stock \"{$openingStock->document_number}\".");

            return $openingStock;
        });
    }

    protected function replaceItems(OpeningStock $openingStock, array $items): void
    {
        $openingStock->items()->delete();

        foreach ($items as $line) {
            $item = $this->itemRepository->findOrFail($line['item_id']);
            $this->qtyCategoryValidator->assertValid($item, $line['qty']);
            $qty = $this->qtyCategoryValidator->round($item, $line['qty']);

            if ((float) $line['unit_cost'] < 0) {
                throw new BusinessException("Unit Cost cannot be negative for item {$item->item_code}.");
            }

            $this->assertCutoffPrecedesExistingActivity($item->id, $openingStock->warehouse_id, $openingStock->cutoff_date, $item->item_code);

            $this->openingStockItemRepository->create([
                'opening_stock_id' => $openingStock->id,
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

    /**
     * The ticket's explicit validation: an Opening Stock is supposed to be the very first thing
     * that ever happened to an item+warehouse — inserting one with a cutoff_date after activity
     * that already exists would retroactively change what "oldest layer" means for consumption
     * that has already happened, corrupting FIFO history. Rejected outright, not just warned —
     * same "protect, don't silently corrupt" policy as FifoLayerService::consume()'s
     * insufficient-stock rejection.
     */
    protected function assertCutoffPrecedesExistingActivity(string $itemId, string $warehouseId, string $cutoffDate, string $itemCode): void
    {
        $hasEarlierActivity = FifoLayer::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('received_date', '<', $cutoffDate)
            ->exists();

        if ($hasEarlierActivity) {
            throw new BusinessException(
                "Cutoff date for item {$itemCode} is after stock activity that already exists for this item/warehouse — this would corrupt FIFO ordering.",
            );
        }
    }

    /**
     * Submits every draft document tagged with one import batch, in one transaction —
     * all-or-nothing, so a single bad line doesn't leave the batch half-submitted.
     */
    public function submitBatch(string $importBatchId): Collection
    {
        return DB::transaction(function () use ($importBatchId) {
            $drafts = $this->openingStockRepository->search(['import_batch_id' => $importBatchId], PHP_INT_MAX)
                ->getCollection()
                ->filter(fn (OpeningStock $doc) => $doc->status === DocumentStatus::DRAFT);

            if ($drafts->isEmpty()) {
                throw new BusinessException('No draft Opening Stock documents found for this import batch.');
            }

            return $drafts->map(fn (OpeningStock $doc) => $this->submit($doc))->values();
        });
    }

    /**
     * Cancels every submitted document tagged with one import batch. Pre-checks every
     * document's layer(s) are unconsumed BEFORE cancelling any of them — same "protect, don't
     * partially corrupt" policy as cancel() itself — and names every blocking document at once
     * rather than failing on the first one found.
     */
    public function cancelBatch(string $importBatchId): Collection
    {
        return DB::transaction(function () use ($importBatchId) {
            $submitted = $this->openingStockRepository->search(['import_batch_id' => $importBatchId], PHP_INT_MAX)
                ->getCollection()
                ->filter(fn (OpeningStock $doc) => $doc->status === DocumentStatus::SUBMITTED);

            if ($submitted->isEmpty()) {
                throw new BusinessException('No submitted Opening Stock documents found for this import batch.');
            }

            $blocked = $submitted->filter(function (OpeningStock $doc) {
                return FifoLayer::query()
                    ->where('source_type', StockVoucherType::OPENING_STOCK->value)
                    ->where('source_id', $doc->id)
                    ->get()
                    ->contains(fn (FifoLayer $layer) => abs((float) $layer->qty_remaining - (float) $layer->qty_in) > 0.00005);
            });

            if ($blocked->isNotEmpty()) {
                $names = $blocked->pluck('document_number')->implode(', ');
                throw new BusinessException("Cannot cancel batch — already partially consumed: {$names}.");
            }

            return $submitted->map(fn (OpeningStock $doc) => $this->cancel($doc))->values();
        });
    }

    protected function assertDraft(OpeningStock $openingStock, string $action): void
    {
        if ($openingStock->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Opening Stocks can be {$action}.");
        }
    }
}
