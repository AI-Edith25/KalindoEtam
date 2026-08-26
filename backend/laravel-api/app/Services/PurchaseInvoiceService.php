<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\PurchaseInvoice;
use App\Repositories\GoodsReceiptRepository;
use App\Repositories\PurchaseInvoiceItemRepository;
use App\Repositories\PurchaseInvoiceRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    protected const EAGER = ['supplier', 'purchaseOrder', 'purchaseOrders', 'goodsReceipt.warehouse', 'goodsReceipts', 'items', 'accountsPayable', 'purchaseReturns'];

    public function __construct(
        protected PurchaseInvoiceRepository $purchaseInvoiceRepository,
        protected PurchaseInvoiceItemRepository $purchaseInvoiceItemRepository,
        protected GoodsReceiptRepository $goodsReceiptRepository,
        protected AccountsPayableService $accountsPayableService,
        protected AccountingService $accountingService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->purchaseInvoiceRepository->search($filters, $perPage);
    }

    /** Unpaginated, same filters as list() — for export. */
    public function listAll(array $filters): Collection
    {
        return $this->purchaseInvoiceRepository->searchAll($filters);
    }

    /**
     * Purchase Invoice items are never entered by the user — they are
     * copied from the selected Goods Receipts' own items, so Invoice
     * content can never drift from what was actually received. One or more
     * Goods Receipts may be combined into a single Invoice as long as they
     * share the same Supplier. purchase_invoices.goods_receipt_id/
     * purchase_order_id keep pointing at the anchor Goods Receipt/Purchase
     * Order (earliest receipt_date, tie-broken by id); goodsReceipts()/
     * purchaseOrders() are the authoritative full source history. Mirrors
     * InvoiceService::createGoods().
     */
    public function create(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data) {
            $goodsReceipts = collect($data['goods_receipt_ids'])
                ->map(fn (string $id) => $this->goodsReceiptRepository->findOrFail($id))
                ->sortBy(fn ($goodsReceipt) => [$goodsReceipt->receipt_date, $goodsReceipt->id])
                ->values();

            foreach ($goodsReceipts as $goodsReceipt) {
                if ($goodsReceipt->status !== DocumentStatus::SUBMITTED) {
                    throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} must be submitted before it can be invoiced.");
                }

                if ($goodsReceipt->purchaseInvoices->isNotEmpty()) {
                    throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} has already been invoiced.");
                }
            }

            if ($goodsReceipts->pluck('supplier_id')->unique()->count() > 1) {
                throw new BusinessException('All selected Goods Receipts must belong to the same Supplier.');
            }

            $anchor = $goodsReceipts->first();

            $subtotal = $goodsReceipts->sum(fn ($goodsReceipt) => (float) $goodsReceipt->items->sum('amount'));
            $taxAmount = (float) ($data['tax_amount'] ?? 0);
            $grandTotal = $subtotal + $taxAmount;

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $purchaseInvoice = $this->purchaseInvoiceRepository->create([
                'goods_receipt_id' => $anchor->id,
                'purchase_order_id' => $anchor->purchase_order_id,
                'supplier_id' => $anchor->supplier_id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'reference_number' => $data['reference_number'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($goodsReceipts as $goodsReceipt) {
                foreach ($goodsReceipt->items as $line) {
                    $this->purchaseInvoiceItemRepository->create([
                        'purchase_invoice_id' => $purchaseInvoice->id,
                        'goods_receipt_item_id' => $line->id,
                        'item_id' => $line->item_id,
                        'item_code' => $line->item_code,
                        'item_name' => $line->item_name,
                        'uom' => $line->uom,
                        'rate' => $line->rate,
                        'qty' => $line->qty,
                        'amount' => $line->amount,
                    ]);
                }
            }

            $purchaseInvoice->goodsReceipts()->sync($goodsReceipts->pluck('id')->all());
            $purchaseInvoice->purchaseOrders()->sync($goodsReceipts->pluck('purchase_order_id')->unique()->all());

            $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
            $this->auditLogService->record('created', 'purchase_invoice', "Created Purchase Invoice \"{$purchaseInvoice->document_number}\".");

            return $purchaseInvoice;
        });
    }

    /** Only header fields are editable — never goods_receipt_id, never items (immutable once created from a Goods Receipt, same posture as Invoice::update()). */
    public function update(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseInvoice, $data) {
            $this->assertDraft($purchaseInvoice, 'updated');

            $taxAmount = array_key_exists('tax_amount', $data) ? (float) $data['tax_amount'] : (float) $purchaseInvoice->tax_amount;
            $grandTotal = (float) $purchaseInvoice->subtotal + $taxAmount;

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $this->purchaseInvoiceRepository->update($purchaseInvoice, [
                'invoice_date' => $data['invoice_date'] ?? $purchaseInvoice->invoice_date,
                'due_date' => $data['due_date'] ?? $purchaseInvoice->due_date,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'reference_number' => array_key_exists('reference_number', $data) ? $data['reference_number'] : $purchaseInvoice->reference_number,
                'remarks' => $data['remarks'] ?? $purchaseInvoice->remarks,
            ]);

            $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'purchase_invoice', "Updated Purchase Invoice \"{$purchaseInvoice->document_number}\".");

            return $purchaseInvoice;
        });
    }

    public function delete(PurchaseInvoice $purchaseInvoice): void
    {
        DB::transaction(function () use ($purchaseInvoice) {
            $this->assertDraft($purchaseInvoice, 'deleted');
            $documentNumber = $purchaseInvoice->document_number;
            $this->purchaseInvoiceRepository->delete($purchaseInvoice);
            $this->auditLogService->record('deleted', 'purchase_invoice', "Deleted Purchase Invoice \"{$documentNumber}\".");
        });
    }

    public function submit(PurchaseInvoice $purchaseInvoice): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseInvoice) {
            $purchaseInvoice->submit();

            $this->accountsPayableService->createFromInvoice($purchaseInvoice);
            $this->accountingService->postForDocument(
                $purchaseInvoice,
                $purchaseInvoice->journalLines(),
                "Purchase Invoice {$purchaseInvoice->document_number}",
                $purchaseInvoice->invoice_date->toDateString(),
            );

            $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
            $this->auditLogService->record('submitted', 'purchase_invoice', "Submitted Purchase Invoice \"{$purchaseInvoice->document_number}\".");

            return $purchaseInvoice;
        });
    }

    /**
     * Cancel -> Create New is the correction path (Purchase Invoice is
     * never edited once submitted). Blocked once any payment has been
     * applied, since there is no partial-reversal workflow for that money.
     *
     * Does NOT reverse any posted Journal Entry or stock movement for this
     * Invoice — ledger corrections for already-invoiced transactions flow
     * through Purchase Return, not through cancelling the Invoice itself.
     */
    public function cancel(PurchaseInvoice $purchaseInvoice): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseInvoice) {
            $accountsPayable = $purchaseInvoice->accountsPayable;

            if ($accountsPayable !== null && (float) $accountsPayable->paid_amount > 0) {
                throw new BusinessException('Cannot cancel a Purchase Invoice that already has payments applied.');
            }

            $purchaseInvoice->cancel();

            if ($accountsPayable !== null) {
                $accountsPayable->delete();
            }

            $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
            $this->auditLogService->record('cancelled', 'purchase_invoice', "Cancelled Purchase Invoice \"{$purchaseInvoice->document_number}\".");

            return $purchaseInvoice;
        });
    }

    protected function assertDraft(PurchaseInvoice $purchaseInvoice, string $action): void
    {
        if ($purchaseInvoice->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Purchase Invoices can be {$action}.");
        }
    }
}
