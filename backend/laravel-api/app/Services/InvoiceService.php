<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\TaxCalculationMode;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Repositories\AccountsReceivableRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\TaxRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    protected const EAGER = ['customer', 'salesOrder', 'delivery', 'items', 'tax', 'accountsReceivable.receiptEntryItems.receiptEntry', 'creditNotes', 'debitNotes'];

    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected InvoiceItemRepository $invoiceItemRepository,
        protected DeliveryRepository $deliveryRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountingService $accountingService,
        protected TaxRepository $taxRepository,
        protected TaxService $taxService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->invoiceRepository->search($filters, $perPage);
    }

    /**
     * Invoice items are never entered by the user — they are copied from
     * the Delivery's own items, so Invoice content can never drift from
     * what was actually delivered. Invoice cannot exist without exactly
     * one source Delivery.
     */
    public function create(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $delivery = $this->deliveryRepository->findOrFail($data['delivery_id']);

            if ($delivery->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException('Delivery must be delivered before it can be invoiced.');
            }

            if ($delivery->invoice !== null) {
                throw new BusinessException('This Delivery has already been invoiced.');
            }

            $subtotal = $delivery->items->sum('amount');
            $discountAmount = $data['discount_amount'] ?? 0;
            [$taxId, $taxAmount] = $this->resolveTax($data, $subtotal);
            $grandTotal = $subtotal - $discountAmount + $taxAmount;

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $invoice = $this->invoiceRepository->create([
                'delivery_id' => $delivery->id,
                'sales_order_id' => $delivery->sales_order_id,
                'customer_id' => $delivery->customer_id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($delivery->items as $line) {
                $this->invoiceItemRepository->create([
                    'invoice_id' => $invoice->id,
                    'delivery_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'item_code' => $line->item_code,
                    'item_name' => $line->item_name,
                    'uom' => $line->uom,
                    'rate' => $line->rate,
                    'qty' => $line->qty,
                    'amount' => $line->amount,
                ]);
            }

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('created', 'invoice', "Created Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /** Only header fields are editable — never delivery_id, never items. */
    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $this->assertDraft($invoice, 'updated');

            $discountAmount = $data['discount_amount'] ?? $invoice->discount_amount;

            // Only re-resolve tax when the caller actually touched it — otherwise keep the
            // invoice's existing tax_id/tax_amount exactly as they were.
            if (array_key_exists('tax_id', $data) || array_key_exists('tax_amount', $data)) {
                [$taxId, $taxAmount] = $this->resolveTax($data, (float) $invoice->subtotal);
            } else {
                $taxId = $invoice->tax_id;
                $taxAmount = $invoice->tax_amount;
            }

            $grandTotal = $invoice->subtotal - $discountAmount + $taxAmount;

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $this->invoiceRepository->update($invoice, [
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'discount_amount' => $discountAmount,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'remarks' => $data['remarks'] ?? $invoice->remarks,
            ]);

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'invoice', "Updated Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /**
     * The single integration point with the Tax Engine — when tax_id is present, TaxService
     * becomes the sole source of truth for tax_amount, overriding any tax_amount also sent in
     * the same payload. When tax_id is absent, tax_amount is trusted directly, the same
     * behavior this field already had before the Tax Engine existed (docs/TAX_ENGINE_DESIGN.md §5).
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

    public function delete(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $this->assertDraft($invoice, 'deleted');
            $documentNumber = $invoice->document_number;
            $this->invoiceRepository->delete($invoice);
            $this->auditLogService->record('deleted', 'invoice', "Deleted Invoice \"{$documentNumber}\".");
        });
    }

    public function submit(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice->submit();

            $this->accountsReceivableService->createFromInvoice($invoice);
            $this->accountingService->postForDocument($invoice, $invoice->journalLines(), "Invoice {$invoice->document_number}", $invoice->invoice_date->toDateString());

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('submitted', 'invoice', "Submitted Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /**
     * Cancel -> Create New is the correction path (Invoice is never
     * edited once submitted). Blocked once any payment has been applied,
     * since there is no partial-reversal workflow for that money.
     *
     * Does NOT reverse any posted Journal Entry for this Invoice — ledger
     * corrections for already-invoiced transactions flow through Credit
     * Note (future module), not through cancelling the Invoice itself.
     */
    public function cancel(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $accountsReceivable = $invoice->accountsReceivable;

            if ($accountsReceivable !== null && (float) $accountsReceivable->paid_amount > 0) {
                throw new BusinessException('Cannot cancel an Invoice that already has payments applied.');
            }

            $invoice->cancel();

            if ($accountsReceivable !== null) {
                $this->accountsReceivableRepository->delete($accountsReceivable);
            }

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('cancelled', 'invoice', "Cancelled Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    protected function assertDraft(Invoice $invoice, string $action): void
    {
        if ($invoice->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Invoices can be {$action}.");
        }
    }
}
