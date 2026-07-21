<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\Delivery;
use App\Models\SalesOrderItem;
use App\Repositories\DeliveryItemRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\SalesOrderItemRepository;
use App\Repositories\SalesOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DeliveryService
{
    public function __construct(
        protected DeliveryRepository $deliveryRepository,
        protected DeliveryItemRepository $deliveryItemRepository,
        protected SalesOrderRepository $salesOrderRepository,
        protected SalesOrderItemRepository $salesOrderItemRepository,
        protected StockLedgerService $stockLedgerService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->deliveryRepository->search($filters, $perPage);
    }

    public function create(array $data): Delivery
    {
        return DB::transaction(function () use ($data) {
            $salesOrder = $this->salesOrderRepository->findOrFail($data['sales_order_id']);

            if ($salesOrder->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException('Sales Order must be submitted before a Delivery can be created against it.');
            }

            $delivery = $this->deliveryRepository->create([
                'sales_order_id' => $salesOrder->id,
                'customer_id' => $salesOrder->customer_id,
                'warehouse_id' => $data['warehouse_id'],
                'delivery_date' => $data['delivery_date'],
                'due_date' => $data['due_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($data['items'] as $line) {
                $this->addLine($delivery, $salesOrder->id, $line['sales_order_item_id'], $line['qty']);
            }

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items']);
            $this->auditLogService->record('created', 'delivery', "Created Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    public function update(Delivery $delivery, array $data): Delivery
    {
        return DB::transaction(function () use ($delivery, $data) {
            $this->assertDraft($delivery, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $delivery->items()->delete();

                foreach ($data['items'] as $line) {
                    $this->addLine($delivery, $delivery->sales_order_id, $line['sales_order_item_id'], $line['qty']);
                }
            }

            $this->deliveryRepository->update($delivery, $headerData);

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items']);
            $this->auditLogService->record('updated', 'delivery', "Updated Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    public function delete(Delivery $delivery): void
    {
        DB::transaction(function () use ($delivery) {
            $this->assertDraft($delivery, 'deleted');
            $documentNumber = $delivery->document_number;
            $this->deliveryRepository->delete($delivery);
            $this->auditLogService->record('deleted', 'delivery', "Deleted Delivery \"{$documentNumber}\".");
        });
    }

    /**
     * The workflow: validate SO, validate outstanding + physical stock,
     * move stock out (StockLedgerService only), advance the SO's
     * delivered_qty, then flip status via Documentable. Accounts
     * Receivable is no longer created here — it is created by
     * InvoiceService::submit() once an Invoice exists for this Delivery.
     */
    public function submit(Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($delivery) {
            $delivery->load(['items.salesOrderItem', 'salesOrder']);

            if ($delivery->salesOrder->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException('Sales Order is no longer submitted; cannot deliver against it.');
            }

            foreach ($delivery->items as $line) {
                $this->assertWithinOutstanding($line->salesOrderItem, $line->qty);
                $this->assertSufficientStock($delivery->warehouse_id, $line->item_id, $line->qty);
            }

            foreach ($delivery->items as $line) {
                $this->stockLedgerService->record(
                    itemId: $line->item_id,
                    warehouseId: $delivery->warehouse_id,
                    transactionType: StockTransactionType::OUT,
                    voucherType: StockVoucherType::DELIVERY,
                    voucherId: $delivery->id,
                    qtyChange: -$line->qty,
                    postingDatetime: now(),
                    referenceNo: $delivery->document_number,
                    remarks: "Delivery {$delivery->document_number}",
                );

                $this->salesOrderItemRepository->incrementDeliveredQty($line->salesOrderItem, $line->qty);
            }

            $delivery->submit();

            $delivery = $delivery->fresh(['customer', 'warehouse', 'salesOrder', 'items']);
            $this->auditLogService->record('submitted', 'delivery', "Submitted Delivery \"{$delivery->document_number}\".");

            return $delivery;
        });
    }

    protected function addLine(Delivery $delivery, string $salesOrderId, string $salesOrderItemId, int $qty): void
    {
        $soItem = $this->resolveSalesOrderItem($salesOrderId, $salesOrderItemId);
        $this->assertWithinOutstanding($soItem, $qty);

        $item = $soItem->item;

        $this->deliveryItemRepository->create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'item_id' => $item->id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'uom' => $item->uom->name,
            'rate' => $soItem->rate,
            'qty' => $qty,
            'amount' => $qty * $soItem->rate,
        ]);
    }

    protected function resolveSalesOrderItem(string $salesOrderId, string $salesOrderItemId): SalesOrderItem
    {
        $soItem = $this->salesOrderItemRepository->findOrFail($salesOrderItemId);

        if ($soItem->sales_order_id !== $salesOrderId) {
            throw new BusinessException('Sales Order item does not belong to the specified Sales Order.');
        }

        return $soItem;
    }

    protected function assertWithinOutstanding(SalesOrderItem $soItem, int $qty): void
    {
        $outstanding = $soItem->qty - $soItem->delivered_qty;

        if ($qty > $outstanding) {
            throw new BusinessException("Delivered qty ({$qty}) exceeds outstanding qty ({$outstanding}) for item {$soItem->item->item_code}.");
        }
    }

    protected function assertSufficientStock(string $warehouseId, string $itemId, int $qty): void
    {
        $available = $this->stockLedgerService->getCurrentBalance($itemId, $warehouseId);

        if ($qty > $available) {
            throw new BusinessException("Insufficient stock: requested {$qty}, available {$available} in this warehouse.");
        }
    }

    protected function assertDraft(Delivery $delivery, string $action): void
    {
        if ($delivery->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Deliveries can be {$action}.");
        }
    }
}
