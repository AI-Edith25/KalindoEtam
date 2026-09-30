<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\PurchaseOrderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
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
        protected QtyCategoryValidator $qtyCategoryValidator,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->purchaseOrderRepository->search($filters, $perPage);
    }

    /** Unpaginated, same filters as list() — for export. */
    public function listAll(array $filters = []): Collection
    {
        return $this->purchaseOrderRepository->searchAll($filters);
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
                'source_document_number' => $data['source_document_number'] ?? null,
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
        if ($purchaseOrder->status === DocumentStatus::SUBMITTED) {
            return $this->updateSubmitted($purchaseOrder, $data);
        }

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

    /**
     * A stakeholder-driven relaxation, same shape as SalesOrderService::updateApproved(): a
     * Submitted PO used to be fully locked (the assertDraft() path above). Now it can still be
     * corrected, but a line that already has goods received against it (received_qty > 0 — the
     * same check PurchaseOrderService::cancel() already uses to block cancelling a received PO)
     * is locked, since editing it after the fact would desync received_qty/outstanding math from
     * what a Goods Receipt already posted. Everything else — every header field, and any line
     * with nothing received against it yet — stays freely editable. No re-approval is triggered:
     * unlike Sales Order there is no supplier-side credit/budget check in this codebase to re-run.
     */
    protected function updateSubmitted(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $data) {
            $headerData = collect($data)->except(['items', 'tax_id', 'tax_amount'])->all();

            if (isset($data['items'])) {
                $this->syncSubmittedItems($purchaseOrder, $data['items']);

                $freshItems = $purchaseOrder->items()->get();
                $totalAmount = round((float) $freshItems->sum('amount'), 2);
                $taxAmount = round((float) $freshItems->sum('tax_amount'), 2);
                $headerData['total_amount'] = $totalAmount;
                $headerData['tax_amount'] = $taxAmount;
                $headerData['grand_total'] = round($totalAmount + $taxAmount, 2);
            }

            if (array_key_exists('tax_id', $data)) {
                $headerData['tax_id'] = $data['tax_id'];
            }

            $this->purchaseOrderRepository->update($purchaseOrder, $headerData);

            $purchaseOrder = $purchaseOrder->fresh(['supplier', 'items.item', 'items.tax', 'tax']);
            $this->auditLogService->record('updated', 'purchase_order', "Updated submitted Purchase Order \"{$purchaseOrder->document_number}\".");

            return $purchaseOrder;
        });
    }

    /**
     * Diff-and-merge, not delete-and-recreate (replaceItems()'s approach) — a line with
     * received_qty > 0 is partly locked: Item/Unit Price/Tax must come back unchanged (a wrong
     * price or tax on an already-received line is corrected via a Goods Receipt return/
     * cancellation, or in the Purchase Invoice — never rewritten out from under what was already
     * received), but Qty may still be raised or lowered as long as it never drops below
     * received_qty (outstanding must never go negative). Mirrors SalesOrderService::
     * syncApprovedItems()'s diff-and-merge shape, just with a softer per-field lock instead of an
     * all-or-nothing one.
     */
    protected function syncSubmittedItems(PurchaseOrder $purchaseOrder, array $items): void
    {
        $existing = $purchaseOrder->items()->get()->keyBy('id');
        $incomingById = collect($items)->filter(fn ($line) => ! empty($line['id']))->keyBy('id');
        $itemsById = Item::query()->with('itemUoms')->whereIn('id', collect($items)->pluck('item_id')->unique())->get()->keyBy('id');

        foreach ($existing as $lineId => $existingLine) {
            if ((float) $existingLine->received_qty <= 0) {
                continue;
            }

            $incomingLine = $incomingById->get($lineId);

            if (! $incomingLine) {
                throw new BusinessException("Line item \"{$existingLine->item?->item_name}\" already has goods received against it and cannot be removed.");
            }

            $uomUnchanged = $itemsById->get($incomingLine['item_id'])?->resolveLineUom($incomingLine['uom_id'] ?? null)['uom_id'] === $existingLine->uom_id;

            if ($incomingLine['item_id'] !== $existingLine->item_id || ! $uomUnchanged || abs((float) $incomingLine['rate'] - (float) $existingLine->rate) >= 0.005 || ($incomingLine['tax_id'] ?? null) !== $existingLine->tax_id) {
                throw new BusinessException("Line item \"{$existingLine->item?->item_name}\" already has goods received against it — Item, UOM, Unit Price and Tax cannot be changed once received (correct via a Goods Receipt return/cancellation, or in the Purchase Invoice instead). Qty may still be adjusted.");
            }

            if ((float) $incomingLine['qty'] < (float) $existingLine->received_qty) {
                throw new BusinessException("Line item \"{$existingLine->item?->item_name}\" qty cannot be reduced below the ".rtrim(rtrim((string) $existingLine->received_qty, '0'), '.')." already received.");
            }
        }

        // Delete-then-recreate is safe here: only unlocked rows reach this point (locked rows
        // were verified present above with nothing but qty differing, and are updated in place
        // below like any other row), and an unlocked row by definition has received_qty === 0.
        foreach ($existing as $lineId => $existingLine) {
            if ((float) $existingLine->received_qty > 0) {
                continue;
            }

            if (! $incomingById->has($lineId)) {
                $existingLine->delete();
            }
        }

        foreach ($items as $line) {
            $item = $itemsById->get($line['item_id']);
            $this->qtyCategoryValidator->assertValid($item, $line['qty']);
            $qty = $this->qtyCategoryValidator->round($item, $line['qty']);
            $lineAmount = $qty * $line['rate'];
            [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $item, 'purchase_tax_id', $lineAmount);
            $uomLine = $item->resolveLineUom($line['uom_id'] ?? null);

            $attributes = [
                'purchase_order_id' => $purchaseOrder->id,
                'item_id' => $line['item_id'],
                'uom_id' => $uomLine['uom_id'],
                'uom_factor' => $uomLine['uom_factor'],
                'qty' => $qty,
                'qty_category' => $item->qty_category,
                'rate' => $line['rate'],
                'amount' => $lineAmount,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
            ];

            if (! empty($line['id']) && $existing->has($line['id'])) {
                $this->purchaseOrderItemRepository->update($existing[$line['id']], $attributes);
            } else {
                $this->purchaseOrderItemRepository->create($attributes + ['received_qty' => 0]);
            }
        }
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

        $itemsById = Item::query()->with('itemUoms')->whereIn('id', collect($items)->pluck('item_id')->unique())->get()->keyBy('id');
        $totalTax = 0.0;

        foreach ($items as $line) {
            $item = $itemsById->get($line['item_id']);
            $this->qtyCategoryValidator->assertValid($item, $line['qty']);
            $qty = $this->qtyCategoryValidator->round($item, $line['qty']);
            $lineAmount = $qty * $line['rate'];
            [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $item, 'purchase_tax_id', $lineAmount);
            // qty/rate are in the chosen UOM; the factor is snapshotted from the item's own UOM list.
            $uomLine = $item->resolveLineUom($line['uom_id'] ?? null);

            $this->purchaseOrderItemRepository->create([
                'purchase_order_id' => $purchaseOrder->id,
                'item_id' => $line['item_id'],
                'uom_id' => $uomLine['uom_id'],
                'uom_factor' => $uomLine['uom_factor'],
                'qty' => $qty,
                'qty_category' => $item->qty_category,
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

    /**
     * A rough pre-rounding subtotal for the header's own "does this even need
     * saving" preview — replaceItems() computes the authoritative per-line
     * amount (using each item's rounded qty) right after this is called.
     */
    protected function sumLines(array $items): float
    {
        return collect($items)->sum(fn (array $line) => $line['qty'] * $line['rate']);
    }
}
