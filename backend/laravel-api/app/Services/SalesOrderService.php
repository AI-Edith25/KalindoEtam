<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\SalesOrder;
use App\Repositories\SalesOrderItemRepository;
use App\Repositories\SalesOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function __construct(
        protected SalesOrderRepository $salesOrderRepository,
        protected SalesOrderItemRepository $salesOrderItemRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->salesOrderRepository->search($filters, $perPage);
    }

    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $salesOrder = $this->salesOrderRepository->create([
                'customer_id' => $data['customer_id'],
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'total_amount' => $this->sumLines($data['items']),
            ]);

            $this->replaceItems($salesOrder, $data['items']);

            $salesOrder = $salesOrder->fresh(['customer', 'items.item']);
            $this->auditLogService->record('created', 'sales_order', "Created Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    public function update(SalesOrder $salesOrder, array $data): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $data) {
            $this->assertDraft($salesOrder, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $this->replaceItems($salesOrder, $data['items']);
                $headerData['total_amount'] = $this->sumLines($data['items']);
            }

            $this->salesOrderRepository->update($salesOrder, $headerData);

            $salesOrder = $salesOrder->fresh(['customer', 'items.item']);
            $this->auditLogService->record('updated', 'sales_order', "Updated Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    public function delete(SalesOrder $salesOrder): void
    {
        DB::transaction(function () use ($salesOrder) {
            $this->assertDraft($salesOrder, 'deleted');
            $documentNumber = $salesOrder->document_number;
            $this->salesOrderRepository->delete($salesOrder);
            $this->auditLogService->record('deleted', 'sales_order', "Deleted Sales Order \"{$documentNumber}\".");
        });
    }

    public function submit(SalesOrder $salesOrder): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder) {
            if ($salesOrder->items()->count() === 0) {
                throw new BusinessException('Cannot submit a Sales Order without items.');
            }

            $salesOrder->submit();
            $this->auditLogService->record('submitted', 'sales_order', "Submitted Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    public function cancel(SalesOrder $salesOrder): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder) {
            $hasDeliveries = $salesOrder->items()->where('delivered_qty', '>', 0)->exists();

            if ($hasDeliveries) {
                throw new BusinessException('Cannot cancel a Sales Order that already has goods delivered against it.');
            }

            $salesOrder->cancel();
            $this->auditLogService->record('cancelled', 'sales_order', "Cancelled Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    protected function assertDraft(SalesOrder $salesOrder, string $action): void
    {
        if ($salesOrder->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Sales Orders can be {$action}.");
        }
    }

    protected function replaceItems(SalesOrder $salesOrder, array $items): void
    {
        $salesOrder->items()->delete();

        foreach ($items as $line) {
            $this->salesOrderItemRepository->create([
                'sales_order_id' => $salesOrder->id,
                'item_id' => $line['item_id'],
                'qty' => $line['qty'],
                'rate' => $line['rate'],
                'amount' => $line['qty'] * $line['rate'],
                'delivered_qty' => 0,
            ]);
        }
    }

    protected function sumLines(array $items): float
    {
        return collect($items)->sum(fn (array $line) => $line['qty'] * $line['rate']);
    }
}
