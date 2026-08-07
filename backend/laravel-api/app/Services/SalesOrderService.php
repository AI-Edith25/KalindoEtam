<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\SalesOrder;
use App\Repositories\SalesOrderItemRepository;
use App\Repositories\SalesOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function __construct(
        protected SalesOrderRepository $salesOrderRepository,
        protected SalesOrderItemRepository $salesOrderItemRepository,
        protected AuditLogService $auditLogService,
        protected CustomerCreditService $customerCreditService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->salesOrderRepository->search($filters, $perPage);
    }

    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $this->enforceCreditCheck(
                $data['customer_id'],
                $this->sumLines($data['items']),
                $data['override_credit_block'] ?? false,
                $data['override_reason'] ?? null,
                'creating Sales Order',
            );

            $salesOrder = $this->salesOrderRepository->create([
                'customer_id' => $data['customer_id'],
                'sales_person_id' => $data['sales_person_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'total_amount' => $this->sumLines($data['items']),
            ]);

            $this->replaceItems($salesOrder, $data['items']);

            $salesOrder = $salesOrder->fresh(['customer', 'salesPerson', 'branch', 'items.item']);
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

            $salesOrder = $salesOrder->fresh(['customer', 'salesPerson', 'branch', 'items.item']);
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

    public function submit(SalesOrder $salesOrder, bool $overrideCreditBlock = false, ?string $overrideReason = null): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $overrideCreditBlock, $overrideReason) {
            if ($salesOrder->items()->count() === 0) {
                throw new BusinessException('Cannot submit a Sales Order without items.');
            }

            $this->enforceCreditCheck(
                $salesOrder->customer_id,
                (float) $salesOrder->total_amount,
                $overrideCreditBlock,
                $overrideReason,
                "submitting Sales Order \"{$salesOrder->document_number}\"",
            );

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

    /**
     * Shared by create() and submit() — a Draft saved while under-limit can
     * drift over-limit (or an invoice can go overdue) by the time it's
     * reopened and submitted, so both write paths re-run the identical
     * check rather than only guarding the initial create. Auth::user()->can()
     * follows ApprovalService::decide()'s own permission-check shape, never
     * a role-name check.
     */
    protected function enforceCreditCheck(string $customerId, float $orderAmount, bool $overridden, ?string $overrideReason, string $context): void
    {
        $credit = $this->customerCreditService->evaluate($customerId, $orderAmount);

        if (! $credit['is_blocked']) {
            return;
        }

        if (! $overridden || ! Auth::user()->can('sales.orders.override_credit_check')) {
            throw new BusinessException($credit['message'], 403);
        }

        $this->auditLogService->record(
            'credit_block_overridden',
            'sales_order',
            "Overrode credit block while {$context}: {$credit['message']}".($overrideReason ? " Reason: {$overrideReason}" : ''),
        );
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
