<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Item;
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
        protected TaxService $taxService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->salesOrderRepository->search($filters, $perPage);
    }

    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data) {
            $subtotal = $this->sumLines($data['items']);

            // Credit check stays on the pre-tax subtotal, deliberately — see enforceCreditCheck().
            $this->enforceCreditCheck(
                $data['customer_id'],
                $subtotal,
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
                'attention' => $data['attention'] ?? null,
                'tel' => $data['tel'] ?? null,
                'fax' => $data['fax'] ?? null,
                'reference' => $data['reference'] ?? null,
                'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
                'total_amount' => $subtotal,
                // tax_id is now purely a "last bulk-applied tax" marker for the header's
                // own "Apply to all lines" convenience — the authoritative tax_amount below
                // is always a sum of the per-line amounts resolveLineTax() computes.
                'tax_id' => $data['tax_id'] ?? null,
                'tax_amount' => 0,
                'grand_total' => $subtotal,
            ]);

            $taxAmount = $this->replaceItems($salesOrder, $data['items']);

            $this->salesOrderRepository->update($salesOrder, [
                'tax_amount' => $taxAmount,
                'grand_total' => round($subtotal + $taxAmount, 2),
            ]);

            $salesOrder = $salesOrder->fresh(['customer', 'salesPerson', 'branch', 'termsOfPayment', 'tax', 'items.item', 'items.tax']);
            $this->auditLogService->record('created', 'sales_order', "Created Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    public function update(SalesOrder $salesOrder, array $data): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $data) {
            $this->assertDraft($salesOrder, 'updated');

            $headerData = collect($data)->except(['items', 'tax_id', 'tax_amount'])->all();

            // Items changing means the per-line tax sum can change too — always recompute
            // together, never reuse a stale cached tax_amount against a new subtotal.
            if (isset($data['items'])) {
                $subtotal = $this->sumLines($data['items']);
                $taxAmount = $this->replaceItems($salesOrder, $data['items']);
                $headerData['total_amount'] = $subtotal;
                $headerData['tax_amount'] = $taxAmount;
                $headerData['grand_total'] = round($subtotal + $taxAmount, 2);
            }

            // tax_id is display-only (the "last bulk-applied tax" marker) — store verbatim
            // if the caller sent one, never used to (re)drive calculation here.
            if (array_key_exists('tax_id', $data)) {
                $headerData['tax_id'] = $data['tax_id'];
            }

            $this->salesOrderRepository->update($salesOrder, $headerData);

            $salesOrder = $salesOrder->fresh(['customer', 'salesPerson', 'branch', 'termsOfPayment', 'tax', 'items.item', 'items.tax']);
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

    /**
     * The single-click Approve action — Submitted -> Approved. Gated by the
     * `sales.orders.approve` route middleware (SalesOrder::requiresApproval()
     * is false, so no ApprovalFlow record is needed here, unlike Purchase
     * Order/Journal Entry). Re-runs the credit check since a Draft-equivalent
     * (Submitted) order saved while under-limit can drift over-limit by the
     * time it's approved.
     */
    public function approve(SalesOrder $salesOrder, bool $overrideCreditBlock = false, ?string $overrideReason = null): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $overrideCreditBlock, $overrideReason) {
            if ($salesOrder->items()->count() === 0) {
                throw new BusinessException('Cannot approve a Sales Order without items.');
            }

            $this->enforceCreditCheck(
                $salesOrder->customer_id,
                (float) $salesOrder->total_amount,
                $overrideCreditBlock,
                $overrideReason,
                "approving Sales Order \"{$salesOrder->document_number}\"",
            );

            $salesOrder->submit();
            $this->auditLogService->record('approved', 'sales_order', "Approved Sales Order \"{$salesOrder->document_number}\".");

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
        if ($salesOrder->status !== SalesOrderStatus::SUBMITTED) {
            throw new BusinessException("Only Sales Orders awaiting approval can be {$action}.");
        }
    }

    /** @return float the sum of every line's resolved tax_amount, for the header's own cache column. */
    protected function replaceItems(SalesOrder $salesOrder, array $items): float
    {
        $salesOrder->items()->delete();

        $itemsById = Item::query()->whereIn('id', collect($items)->pluck('item_id')->unique())->get()->keyBy('id');
        $totalTax = 0.0;

        foreach ($items as $line) {
            $lineAmount = $line['qty'] * $line['rate'];
            [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $itemsById->get($line['item_id']), 'sales_tax_id', $lineAmount);

            $this->salesOrderItemRepository->create([
                'sales_order_id' => $salesOrder->id,
                'item_id' => $line['item_id'],
                'qty' => $line['qty'],
                'rate' => $line['rate'],
                'amount' => $lineAmount,
                'delivered_qty' => 0,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
            ]);

            $totalTax += $taxAmount;
        }

        return round($totalTax, 2);
    }

    protected function sumLines(array $items): float
    {
        return collect($items)->sum(fn (array $line) => $line['qty'] * $line['rate']);
    }
}
