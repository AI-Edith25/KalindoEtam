<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\PurchaseOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Order is the closest existing Purchase document to gain tax
 * support — no Purchase Invoice/Bill document type exists yet in this
 * codebase. Per-line tax defaults from each line's Item.purchase_tax_id
 * via TaxService::resolveLineTax(); the header tax_amount is a sum of
 * line amounts. Never posts a journal entry (Purchase Order doesn't
 * today, unchanged) — this is calculation only. See docs/TAX_ENGINE_DESIGN.md §5/§6.
 */
class PurchaseOrderService
{
    public function __construct(
        protected PurchaseOrderRepository $purchaseOrderRepository,
        protected PurchaseOrderItemRepository $purchaseOrderItemRepository,
        protected TaxService $taxService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->purchaseOrderRepository->search($filters, $perPage);
    }

    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $subtotal = $this->sumLines($data['items']);

            $purchaseOrder = $this->purchaseOrderRepository->create([
                'supplier_id' => $data['supplier_id'],
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'total_amount' => $subtotal,
                // tax_id is now purely a "last bulk-applied tax" marker for the header's
                // own "Apply to all lines" convenience — the authoritative tax_amount below
                // is always a sum of the per-line amounts resolveLineTax() computes.
                'tax_id' => $data['tax_id'] ?? null,
                'tax_amount' => 0,
                'grand_total' => $subtotal,
            ]);

            $taxAmount = $this->replaceItems($purchaseOrder, $data['items']);

            $this->purchaseOrderRepository->update($purchaseOrder, [
                'tax_amount' => $taxAmount,
                'grand_total' => round($subtotal + $taxAmount, 2),
            ]);

            $purchaseOrder = $purchaseOrder->fresh(['supplier', 'items.item', 'items.tax', 'tax']);
            $this->auditLogService->record('created', 'purchase_order', "Created Purchase Order \"{$purchaseOrder->document_number}\".");

            return $purchaseOrder;
        });
    }

    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $data) {
            $this->assertDraft($purchaseOrder, 'updated');

            $headerData = collect($data)->except(['items', 'tax_id', 'tax_amount'])->all();

            // Items changing means the per-line tax sum can change too — always recompute
            // together, never reuse a stale cached tax_amount against a new subtotal.
            if (isset($data['items'])) {
                $subtotal = $this->sumLines($data['items']);
                $taxAmount = $this->replaceItems($purchaseOrder, $data['items']);
                $headerData['total_amount'] = $subtotal;
                $headerData['tax_amount'] = $taxAmount;
                $headerData['grand_total'] = round($subtotal + $taxAmount, 2);
            }

            // tax_id is display-only (the "last bulk-applied tax" marker) — store verbatim
            // if the caller sent one, never used to (re)drive calculation here.
            if (array_key_exists('tax_id', $data)) {
                $headerData['tax_id'] = $data['tax_id'];
            }

            $this->purchaseOrderRepository->update($purchaseOrder, $headerData);

            $purchaseOrder = $purchaseOrder->fresh(['supplier', 'items.item', 'items.tax', 'tax']);
            $this->auditLogService->record('updated', 'purchase_order', "Updated Purchase Order \"{$purchaseOrder->document_number}\".");

            return $purchaseOrder;
        });
    }

    public function delete(PurchaseOrder $purchaseOrder): void
    {
        DB::transaction(function () use ($purchaseOrder) {
            $this->assertDraft($purchaseOrder, 'deleted');
            $documentNumber = $purchaseOrder->document_number;
            $this->purchaseOrderRepository->delete($purchaseOrder);
            $this->auditLogService->record('deleted', 'purchase_order', "Deleted Purchase Order \"{$documentNumber}\".");
        });
    }

    public function submit(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder) {
            if ($purchaseOrder->items()->count() === 0) {
                throw new BusinessException('Cannot submit a Purchase Order without items.');
            }

            $purchaseOrder->submit();
            $this->auditLogService->record('submitted', 'purchase_order', "Submitted Purchase Order \"{$purchaseOrder->document_number}\".");

            return $purchaseOrder;
        });
    }

    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder) {
            $hasReceipts = $purchaseOrder->items()->where('received_qty', '>', 0)->exists();

            if ($hasReceipts) {
                throw new BusinessException('Cannot cancel a Purchase Order that already has goods received against it.');
            }

            $purchaseOrder->cancel();
            $this->auditLogService->record('cancelled', 'purchase_order', "Cancelled Purchase Order \"{$purchaseOrder->document_number}\".");

            return $purchaseOrder;
        });
    }

    protected function assertDraft(PurchaseOrder $purchaseOrder, string $action): void
    {
        if ($purchaseOrder->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Purchase Orders can be {$action}.");
        }
    }

    /** @return float the sum of every line's resolved tax_amount, for the header's own cache column. */
    protected function replaceItems(PurchaseOrder $purchaseOrder, array $items): float
    {
        $purchaseOrder->items()->delete();

        $itemsById = Item::query()->whereIn('id', collect($items)->pluck('item_id')->unique())->get()->keyBy('id');
        $totalTax = 0.0;

        foreach ($items as $line) {
            $lineAmount = $line['qty'] * $line['rate'];
            [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $itemsById->get($line['item_id']), 'purchase_tax_id', $lineAmount);

            $this->purchaseOrderItemRepository->create([
                'purchase_order_id' => $purchaseOrder->id,
                'item_id' => $line['item_id'],
                'qty' => $line['qty'],
                'rate' => $line['rate'],
                'amount' => $lineAmount,
                'received_qty' => 0,
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
