<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Exceptions\BusinessException;
use App\Exports\Concerns\BuildsSalesSummaryReport;
use App\Models\Item;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Repositories\CompanyRepository;
use App\Repositories\SalesOrderItemRepository;
use App\Repositories\SalesOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    use BuildsSalesSummaryReport;

    public function __construct(
        protected SalesOrderRepository $salesOrderRepository,
        protected SalesOrderItemRepository $salesOrderItemRepository,
        protected AuditLogService $auditLogService,
        protected CustomerCreditService $customerCreditService,
        protected SalesOrderStockService $salesOrderStockService,
        protected TaxService $taxService,
        protected CompanyRepository $companyRepository,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->salesOrderRepository->search($filters, $perPage);
    }

    /** Unpaginated, for bulk export/print — same filters as list(), plus an optional $ids override. */
    public function listAll(array $filters = [], ?array $ids = null): Collection
    {
        return $this->salesOrderRepository->searchAll($filters, $ids);
    }

    /**
     * The "Summary" export variant — see BuildsSalesSummaryReport. Tax is
     * grouped at header level ($order->tax, already eager-loaded).
     *
     * @return array{rows: array, meta: array}
     */
    public function summaryExportRows(array $filters, ?array $ids = null): array
    {
        $orders = $this->salesOrderRepository->searchAll($filters, $ids);

        $bodyRows = $orders->map(fn (SalesOrder $order) => [
            $this->summaryExcelDate($order->order_date),
            $order->document_number,
            $order->customer?->customer_code,
            $order->customer?->customer_name,
            (float) $order->total_amount,
            0.0,
            (float) $order->tax_amount,
            (float) $order->grand_total,
            $order->salesPerson?->code,
            $order->branch?->code,
            $order->branch?->code,
        ])->all();

        $bodyRows[] = [
            null, null, null, 'Total By Header',
            round($orders->sum(fn (SalesOrder $o) => (float) $o->total_amount), 2),
            0.0,
            round($orders->sum(fn (SalesOrder $o) => (float) $o->tax_amount), 2),
            round($orders->sum(fn (SalesOrder $o) => (float) $o->grand_total), 2),
            null, null, null,
        ];

        $taxGroups = $this->groupTaxSummary($orders, fn (SalesOrder $o) => [
            [$o->tax?->code, (float) ($o->tax?->rate ?? 0), (float) $o->total_amount, (float) $o->tax_amount],
        ]);

        return $this->buildSalesSummaryReport(
            title: 'SALES ORDER LISTING - SUMMARY',
            periodLabel: $this->summaryPeriodLabel($filters, $orders, 'order_date'),
            companyName: $this->companyRepository->defaultOrById(null)?->name ?? 'PT. KALINDO ETAM',
            headingRow: ['Date', 'Document', 'Customer', 'Customer Name', 'Excl.Tax', 'Disc', 'Tax', 'Incl.Tax', 'Sales Person', 'Delivery Location', 'Branch'],
            bodyRows: $bodyRows,
            taxGroups: $taxGroups,
            printedBy: Auth::user()?->name ?? 'System',
            lastColumn: 'K',
            numberFormatColumns: ['E', 'F', 'G', 'H'],
        );
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

            if (! empty($data['warehouse_id'])) {
                $this->enforceStockCheck(
                    $data['items'],
                    $data['warehouse_id'],
                    $data['override_stock_block'] ?? false,
                    $data['stock_override_reason'] ?? null,
                    'creating Sales Order',
                );
            }

            $salesOrder = $this->salesOrderRepository->create([
                'customer_id' => $data['customer_id'],
                'sales_person_id' => $data['sales_person_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
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

            $salesOrder = $salesOrder->fresh(['customer', 'salesPerson', 'branch', 'warehouse', 'termsOfPayment', 'tax', 'items.item', 'items.tax']);
            $this->auditLogService->record('created', 'sales_order', "Created Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    public function update(SalesOrder $salesOrder, array $data): SalesOrder
    {
        if ($salesOrder->status === SalesOrderStatus::APPROVED) {
            return $this->updateApproved($salesOrder, $data);
        }

        return DB::transaction(function () use ($salesOrder, $data) {
            $this->assertDraft($salesOrder, 'updated');

            $headerData = collect($data)->except(['items', 'tax_id', 'tax_amount', 'override_stock_block', 'stock_override_reason'])->all();

            // Items changing means the per-line tax sum can change too — always recompute
            // together, never reuse a stale cached tax_amount against a new subtotal.
            if (isset($data['items'])) {
                $warehouseId = $data['warehouse_id'] ?? $salesOrder->warehouse_id;

                // Unlike the credit check (create/approve only), stock is re-checked on every
                // edit that touches qty/items — an over-limit credit drift is fine to catch only
                // at approve(), but a qty edit that now exceeds stock should surface immediately,
                // not silently wait for approval.
                if (! empty($warehouseId)) {
                    $this->enforceStockCheck(
                        $data['items'],
                        $warehouseId,
                        $data['override_stock_block'] ?? false,
                        $data['stock_override_reason'] ?? null,
                        "updating Sales Order \"{$salesOrder->document_number}\"",
                        $salesOrder->id,
                    );
                }

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

            $salesOrder = $salesOrder->fresh(['customer', 'salesPerson', 'branch', 'warehouse', 'termsOfPayment', 'tax', 'items.item', 'items.tax']);
            $this->auditLogService->record('updated', 'sales_order', "Updated Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    /**
     * A stakeholder-driven relaxation: an Approved order used to be fully locked (the
     * assertDraft() path above). Now it can still be corrected, but a line a Delivery already
     * references (even a still-Pending one — DeliveryItem exists as soon as the Delivery is
     * created, before delivered_qty ever moves) is locked, since sales_order_items.id is
     * restrictOnDelete() from delivery_items. Everything else — every header field, and any
     * line with no Delivery against it yet — stays freely editable. Unlike update() above,
     * this also re-runs the credit check: approve() was the only place that ever re-checked it,
     * and an Approved order edited afterward has no later approve() call to catch a drift over
     * the customer's limit.
     */
    protected function updateApproved(SalesOrder $salesOrder, array $data): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $data) {
            $headerData = collect($data)->except(['items', 'tax_id', 'tax_amount', 'override_credit_block', 'override_reason', 'override_stock_block', 'stock_override_reason'])->all();

            $subtotal = (float) $salesOrder->total_amount;
            $this->enforceCreditCheck(
                $data['customer_id'] ?? $salesOrder->customer_id,
                isset($data['items']) ? $this->sumLines($data['items']) : $subtotal,
                $data['override_credit_block'] ?? false,
                $data['override_reason'] ?? null,
                "updating approved Sales Order \"{$salesOrder->document_number}\"",
            );

            if (isset($data['items'])) {
                $warehouseId = $data['warehouse_id'] ?? $salesOrder->warehouse_id;

                if (! empty($warehouseId)) {
                    $this->enforceStockCheck(
                        $data['items'],
                        $warehouseId,
                        $data['override_stock_block'] ?? false,
                        $data['stock_override_reason'] ?? null,
                        "updating approved Sales Order \"{$salesOrder->document_number}\"",
                        $salesOrder->id,
                    );
                }

                $this->syncApprovedItems($salesOrder, $data['items']);

                $freshItems = $salesOrder->items()->get();
                $totalAmount = round((float) $freshItems->sum('amount'), 2);
                $taxAmount = round((float) $freshItems->sum('tax_amount'), 2);
                $headerData['total_amount'] = $totalAmount;
                $headerData['tax_amount'] = $taxAmount;
                $headerData['grand_total'] = round($totalAmount + $taxAmount, 2);
            }

            if (array_key_exists('tax_id', $data)) {
                $headerData['tax_id'] = $data['tax_id'];
            }

            $this->salesOrderRepository->update($salesOrder, $headerData);

            $salesOrder = $salesOrder->fresh(['customer', 'salesPerson', 'branch', 'warehouse', 'termsOfPayment', 'tax', 'items.item', 'items.tax', 'items.deliveryItems']);
            $this->auditLogService->record('updated', 'sales_order', "Updated approved Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    /**
     * Diff-and-merge, not delete-and-recreate (replaceItems()'s approach), because a line
     * already referenced by a DeliveryItem can't be deleted — restrictOnDelete() on
     * delivery_items.sales_order_item_id throws at the DB level. A line is "locked" once any
     * DeliveryItem references it (regardless of delivered_qty, which only moves once that
     * Delivery is completed): it must appear in $items unchanged, or this rejects the whole
     * edit rather than silently dropping the caller's attempt to touch it.
     */
    protected function syncApprovedItems(SalesOrder $salesOrder, array $items): void
    {
        $existing = $salesOrder->items()->with('deliveryItems')->get()->keyBy('id');
        $incomingById = collect($items)->filter(fn ($line) => ! empty($line['id']))->keyBy('id');

        foreach ($existing as $itemId => $existingLine) {
            /** @var SalesOrderItem $existingLine */
            $isLocked = $existingLine->deliveryItems->isNotEmpty();
            $incomingLine = $incomingById->get($itemId);

            if (! $isLocked) {
                continue;
            }

            $unchanged = $incomingLine
                && $incomingLine['item_id'] === $existingLine->item_id
                && (int) $incomingLine['qty'] === (int) $existingLine->qty
                && abs((float) $incomingLine['rate'] - (float) $existingLine->rate) < 0.005
                && ($incomingLine['tax_id'] ?? null) === $existingLine->tax_id;

            if (! $unchanged) {
                throw new BusinessException("Line item \"{$existingLine->item?->item_name}\" already has a Delivery against it and cannot be changed or removed.");
            }
        }

        $itemsById = Item::query()->whereIn('id', collect($items)->pluck('item_id')->unique())->get()->keyBy('id');

        // Delete-then-recreate is safe here: only unlocked rows reach this point (locked rows
        // were verified unchanged and left alone above), and an unlocked row by definition has
        // no DeliveryItem referencing it yet.
        foreach ($existing as $itemId => $existingLine) {
            if ($existingLine->deliveryItems->isNotEmpty()) {
                continue;
            }

            if (! $incomingById->has($itemId)) {
                $existingLine->delete();
            }
        }

        foreach ($items as $line) {
            if (! empty($line['id']) && $existing->has($line['id']) && $existing[$line['id']]->deliveryItems->isNotEmpty()) {
                continue; // Locked — already verified unchanged above, never rewritten.
            }

            $lineAmount = $line['qty'] * $line['rate'];
            [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $itemsById->get($line['item_id']), 'sales_tax_id', $lineAmount);

            $attributes = [
                'sales_order_id' => $salesOrder->id,
                'item_id' => $line['item_id'],
                'qty' => $line['qty'],
                'rate' => $line['rate'],
                'amount' => $lineAmount,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
            ];

            if (! empty($line['id']) && $existing->has($line['id'])) {
                $this->salesOrderItemRepository->update($existing[$line['id']], $attributes);
            } else {
                $this->salesOrderItemRepository->create($attributes + ['delivered_qty' => 0]);
            }
        }
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
     * time it's approved. Same "can drift" reasoning applies to stock — see
     * enforceStockCheck().
     */
    public function approve(
        SalesOrder $salesOrder,
        bool $overrideCreditBlock = false,
        ?string $overrideReason = null,
        bool $overrideStockBlock = false,
        ?string $stockOverrideReason = null,
    ): SalesOrder {
        return DB::transaction(function () use ($salesOrder, $overrideCreditBlock, $overrideReason, $overrideStockBlock, $stockOverrideReason) {
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

            if (! empty($salesOrder->warehouse_id)) {
                $this->enforceStockCheck(
                    $salesOrder->items->map(fn ($item) => ['item_id' => $item->item_id, 'qty' => $item->qty])->all(),
                    $salesOrder->warehouse_id,
                    $overrideStockBlock,
                    $stockOverrideReason,
                    "approving Sales Order \"{$salesOrder->document_number}\"",
                    $salesOrder->id,
                );
            }

            $salesOrder->submit();
            $this->auditLogService->record('approved', 'sales_order', "Approved Sales Order \"{$salesOrder->document_number}\".");

            return $salesOrder;
        });
    }

    /**
     * Sales Order Detail page's own Approve button has no live form to recompute a client-side
     * preview against (unlike the Editor page, which denormalizes available_qty onto each line at
     * pick time) — this is that page's equivalent read, same shape CustomerController::
     * creditStatus() already provides for the credit block.
     *
     * @return array{is_blocked: bool, message: string, lines: array}
     */
    public function stockStatusFor(SalesOrder $salesOrder): array
    {
        if (empty($salesOrder->warehouse_id)) {
            return ['is_blocked' => false, 'message' => '', 'lines' => []];
        }

        $items = $salesOrder->items->map(fn ($item) => ['item_id' => $item->item_id, 'qty' => $item->qty])->all();

        return $this->salesOrderStockService->evaluate($items, $salesOrder->warehouse_id, $salesOrder->id);
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

    /**
     * Shared by create(), update() (only when items change), and approve() — see
     * SalesOrderStockService for why this exists at all and how "available" is computed. A
     * distinct override flag/reason/permission from the credit check, on purpose — so an audit
     * log entry is unambiguous about which concern was overridden when both happen to co-occur.
     *
     * @param  array<int, array{item_id: string, qty: int|float}>  $items
     */
    protected function enforceStockCheck(array $items, string $warehouseId, bool $overridden, ?string $overrideReason, string $context, ?string $excludeSalesOrderId = null): void
    {
        $stock = $this->salesOrderStockService->evaluate($items, $warehouseId, $excludeSalesOrderId);

        if (! $stock['is_blocked']) {
            return;
        }

        if (! $overridden || ! Auth::user()->can('sales.orders.override_stock_check')) {
            throw new BusinessException($stock['message'], 403);
        }

        $this->auditLogService->record(
            'stock_block_overridden',
            'sales_order',
            "Overrode stock block while {$context}: {$stock['message']}".($overrideReason ? " Reason: {$overrideReason}" : ''),
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
