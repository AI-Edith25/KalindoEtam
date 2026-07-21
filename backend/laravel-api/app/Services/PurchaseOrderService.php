<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\TaxCalculationMode;
use App\Exceptions\BusinessException;
use App\Models\PurchaseOrder;
use App\Repositories\PurchaseOrderItemRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\TaxRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Order is the closest existing Purchase document to gain tax
 * support — no Purchase Invoice/Bill document type exists yet in this
 * codebase. Reuses TaxService::calculate() identically to InvoiceService,
 * the same "one calculation, many callers" shape. Never posts a journal
 * entry (Purchase Order doesn't today, unchanged) — this is calculation
 * only. See docs/TAX_ENGINE_DESIGN.md §5/§6.
 */
class PurchaseOrderService
{
    public function __construct(
        protected PurchaseOrderRepository $purchaseOrderRepository,
        protected PurchaseOrderItemRepository $purchaseOrderItemRepository,
        protected TaxRepository $taxRepository,
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
            [$taxId, $taxAmount] = $this->resolveTax($data, $subtotal);

            $purchaseOrder = $this->purchaseOrderRepository->create([
                'supplier_id' => $data['supplier_id'],
                'order_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'total_amount' => $subtotal,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
                'grand_total' => round($subtotal + $taxAmount, 2),
            ]);

            $this->replaceItems($purchaseOrder, $data['items']);

            $purchaseOrder = $purchaseOrder->fresh(['supplier', 'items.item', 'tax']);
            $this->auditLogService->record('created', 'purchase_order', "Created Purchase Order \"{$purchaseOrder->document_number}\".");

            return $purchaseOrder;
        });
    }

    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $data) {
            $this->assertDraft($purchaseOrder, 'updated');

            $headerData = collect($data)->except(['items', 'tax_id', 'tax_mode'])->all();

            $subtotal = (float) $purchaseOrder->total_amount;
            if (isset($data['items'])) {
                $this->replaceItems($purchaseOrder, $data['items']);
                $subtotal = $this->sumLines($data['items']);
                $headerData['total_amount'] = $subtotal;
            }

            if (array_key_exists('tax_id', $data) || array_key_exists('tax_amount', $data)) {
                [$taxId, $taxAmount] = $this->resolveTax($data, $subtotal);
                $headerData['tax_id'] = $taxId;
                $headerData['tax_amount'] = $taxAmount;
                $headerData['grand_total'] = round($subtotal + $taxAmount, 2);
            } elseif (isset($data['items'])) {
                // Subtotal changed but tax selection didn't — re-total against the same tax.
                $headerData['grand_total'] = round($subtotal + (float) $purchaseOrder->tax_amount, 2);
            }

            $this->purchaseOrderRepository->update($purchaseOrder, $headerData);

            $purchaseOrder = $purchaseOrder->fresh(['supplier', 'items.item', 'tax']);
            $this->auditLogService->record('updated', 'purchase_order', "Updated Purchase Order \"{$purchaseOrder->document_number}\".");

            return $purchaseOrder;
        });
    }

    /** Identical contract to InvoiceService::resolveTax() — same TaxService, same fallback rule. See docs/TAX_ENGINE_DESIGN.md §6.
     *
     * @return array{0: ?string, 1: float} [taxId, taxAmount]
     */
    protected function resolveTax(array $data, float $subtotal): array
    {
        if (! empty($data['tax_id'])) {
            $tax = $this->taxRepository->findOrFail($data['tax_id']);
            $mode = TaxCalculationMode::from($data['tax_mode'] ?? TaxCalculationMode::EXCLUSIVE->value);
            $taxAmount = $this->taxService->calculate($subtotal, $tax, $mode)['tax_amount'];

            return [$tax->id, $taxAmount];
        }

        return [null, (float) ($data['tax_amount'] ?? 0)];
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

    protected function replaceItems(PurchaseOrder $purchaseOrder, array $items): void
    {
        $purchaseOrder->items()->delete();

        foreach ($items as $line) {
            $this->purchaseOrderItemRepository->create([
                'purchase_order_id' => $purchaseOrder->id,
                'item_id' => $line['item_id'],
                'qty' => $line['qty'],
                'rate' => $line['rate'],
                'amount' => $line['qty'] * $line['rate'],
                'received_qty' => 0,
            ]);
        }
    }

    protected function sumLines(array $items): float
    {
        return collect($items)->sum(fn (array $line) => $line['qty'] * $line['rate']);
    }
}
