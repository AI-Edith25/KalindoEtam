<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\DeliveryStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
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
    protected const EAGER = ['customer', 'salesPerson', 'salesOrder', 'salesOrders', 'branch', 'delivery.warehouse', 'deliveries', 'items.tax', 'tax', 'termsOfPayment', 'accountsReceivable.receiptEntryItems.receiptEntry.cashAccount', 'creditNotes', 'debitNotes'];

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
     * the selected Deliveries' own items, so Invoice content can never
     * drift from what was actually delivered. One or more Deliveries may
     * be combined into a single Invoice as long as they share the same
     * Customer. invoices.delivery_id/sales_order_id keep pointing at the
     * anchor Delivery/Sales Order (earliest delivery_date, tie-broken by
     * id — deterministic regardless of selection order) purely for
     * backward compatibility with existing readers (including the frozen
     * AccountsReceivableService); deliveries()/salesOrders() are the
     * authoritative full source history.
     */
    public function create(array $data): Invoice
    {
        if (($data['invoice_type'] ?? InvoiceType::GOODS->value) === InvoiceType::TRANSPORTATION->value) {
            return $this->createTransportation($data);
        }

        return $this->createGoods($data);
    }

    protected function createGoods(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $deliveries = collect($data['delivery_ids'])
                ->map(fn (string $id) => $this->deliveryRepository->findOrFail($id))
                ->sortBy(fn ($delivery) => [$delivery->delivery_date, $delivery->id])
                ->values();

            foreach ($deliveries as $delivery) {
                if ($delivery->status !== DeliveryStatus::COMPLETE) {
                    throw new BusinessException("Delivery {$delivery->document_number} must be delivered before it can be invoiced.");
                }

                if ($delivery->invoices->isNotEmpty()) {
                    throw new BusinessException("Delivery {$delivery->document_number} has already been invoiced.");
                }
            }

            if ($deliveries->pluck('customer_id')->unique()->count() > 1) {
                throw new BusinessException('All selected Deliveries must belong to the same Customer.');
            }

            $anchor = $deliveries->first();

            $subtotal = $deliveries->sum(fn ($delivery) => (float) $delivery->items->sum('amount'));
            [$discountAmount, $discountType, $discountPercentage] = $this->resolveDiscount($data, $subtotal);
            // Goods invoices have no single header tax anymore — each line's tax was already
            // resolved when its Sales Order line/Delivery line was created (Item.sales_tax_id
            // default or a manual per-line override); this invoice just sums what it copies below.
            $taxAmount = round($deliveries->sum(fn ($delivery) => (float) $delivery->items->sum('tax_amount')), 2);
            $grandTotal = $subtotal - $discountAmount + $taxAmount;

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $invoice = $this->invoiceRepository->create([
                'delivery_id' => $anchor->id,
                'sales_order_id' => $anchor->sales_order_id,
                'customer_id' => $anchor->customer_id,
                'sales_person_id' => $data['sales_person_id'] ?? $anchor->salesOrder?->sales_person_id,
                // Defaults to Goods — every caller that predates Sprint 2 (Invoice Numbering),
                // including existing tests, never passes this and means Goods either way.
                'invoice_type' => $data['invoice_type'] ?? InvoiceType::GOODS->value,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_type' => $discountType->value,
                'discount_percentage' => $discountPercentage,
                // Header tax_id has no single meaningful value once tax is per-line — always
                // null for Goods, tax_amount above is the authoritative sum of the lines.
                'tax_id' => null,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'remarks' => $data['remarks'] ?? null,
                // Auto-prefilled from the anchor Sales Order's number for Goods invoices
                // (still overridable via $data['reference_1']) — UAT review 2026-08-12.
                'reference_1' => $data['reference_1'] ?? $anchor->salesOrder?->document_number,
                'reference_2' => $data['reference_2'] ?? null,
            ]);

            foreach ($deliveries as $delivery) {
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
                        // Copied verbatim from the DeliveryItem — already resolved upstream,
                        // same frozen-snapshot treatment as item_code/item_name/uom above.
                        'tax_id' => $line->tax_id,
                        'tax_amount' => $line->tax_amount,
                    ]);
                }
            }

            $invoice->deliveries()->sync($deliveries->pluck('id')->all());
            $invoice->salesOrders()->sync($deliveries->pluck('sales_order_id')->unique()->all());

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('created', 'invoice', "Created Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /**
     * Transportation invoices carry no Sales Order/Delivery at all — a
     * transport service was never delivered via one. Customer is picked
     * directly, items are freestanding (delivery_item_id/item_id null,
     * item_name holds the typed description — the same column Goods uses
     * for a real Item's name), and no Delivery pivot rows are synced since
     * there's nothing to attach. Never touches stock, same as Goods —
     * Invoice creation has never called any stock/inventory service.
     */
    protected function createTransportation(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $subtotal = array_sum(array_map(
                fn (array $line) => (float) $line['qty'] * (float) $line['rate'],
                $data['items']
            ));

            [$discountAmount, $discountType, $discountPercentage] = $this->resolveDiscount($data, $subtotal);
            [$taxId, $taxAmount] = $this->resolveTax($data, $subtotal);
            $grandTotal = $subtotal - $discountAmount + $taxAmount;

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $invoice = $this->invoiceRepository->create([
                'delivery_id' => null,
                'sales_order_id' => null,
                'branch_id' => $data['branch_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'sales_person_id' => $data['sales_person_id'] ?? null,
                'invoice_type' => InvoiceType::TRANSPORTATION->value,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_type' => $discountType->value,
                'discount_percentage' => $discountPercentage,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'remarks' => $data['remarks'] ?? null,
                // No Sales Order to derive from — manual entry only (e.g. the related SI number).
                'reference_1' => $data['reference_1'] ?? null,
                'reference_2' => $data['reference_2'] ?? null,
            ]);

            foreach ($data['items'] as $line) {
                $qty = (float) $line['qty'];
                $rate = (float) $line['rate'];

                $this->invoiceItemRepository->create([
                    'invoice_id' => $invoice->id,
                    'delivery_item_id' => null,
                    'item_id' => null,
                    'item_code' => null,
                    'item_name' => $line['description'],
                    'uom' => null,
                    'rate' => $rate,
                    'qty' => $qty,
                    'amount' => $qty * $rate,
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

            // Only re-resolve discount when the caller actually touched it — otherwise keep the
            // invoice's existing discount_amount/discount_type/discount_percentage exactly as they were.
            if (array_key_exists('discount_type', $data) || array_key_exists('discount_amount', $data) || array_key_exists('discount_percentage', $data)) {
                [$discountAmount, $discountType, $discountPercentage] = $this->resolveDiscount($data, (float) $invoice->subtotal);
            } else {
                $discountAmount = $invoice->discount_amount;
                $discountType = $invoice->discount_type;
                $discountPercentage = $invoice->discount_percentage;
            }

            // Goods invoices never accept an independent tax choice — items aren't editable
            // on Invoice (see this method's own docblock), so tax_id/tax_amount always stay
            // whatever they were resolved to at creation (a sum of each line's own tax,
            // copied forward from the source Delivery/Sales Order line), regardless of what
            // the request sends. Only Transportation (no Sales Order, no Item-backed lines)
            // re-resolves tax when the caller actually touches it.
            if ($invoice->invoice_type === InvoiceType::TRANSPORTATION && (array_key_exists('tax_id', $data) || array_key_exists('tax_amount', $data))) {
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
                'terms_of_payment_id' => array_key_exists('terms_of_payment_id', $data) ? $data['terms_of_payment_id'] : $invoice->terms_of_payment_id,
                'discount_amount' => $discountAmount,
                'discount_type' => $discountType instanceof DiscountType ? $discountType->value : $discountType,
                'discount_percentage' => $discountPercentage,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'remarks' => $data['remarks'] ?? $invoice->remarks,
                'sales_person_id' => array_key_exists('sales_person_id', $data) ? $data['sales_person_id'] : $invoice->sales_person_id,
                'reference_1' => array_key_exists('reference_1', $data) ? $data['reference_1'] : $invoice->reference_1,
                'reference_2' => array_key_exists('reference_2', $data) ? $data['reference_2'] : $invoice->reference_2,
            ]);

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'invoice', "Updated Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /**
     * Mirrors resolveTax()'s shape: Amount mode trusts discount_amount directly (the same
     * behavior this field already had before Discount Type existed, so pre-Sprint-3.1 rows
     * and callers keep working untouched — they default to Amount via the migration/enum
     * default). Percentage mode derives discount_amount from discount_percentage here, the
     * one place both create() and update() compute it from.
     *
     * @return array{0: float, 1: DiscountType, 2: ?float} [discountAmount, discountType, discountPercentage]
     */
    protected function resolveDiscount(array $data, float $subtotal): array
    {
        $type = isset($data['discount_type']) ? DiscountType::from($data['discount_type']) : DiscountType::AMOUNT;

        if ($type === DiscountType::PERCENTAGE) {
            $percentage = (float) ($data['discount_percentage'] ?? 0);

            if ($percentage < 0 || $percentage > 100) {
                throw new BusinessException('Discount percentage must be between 0 and 100.');
            }

            $amount = round($subtotal * $percentage / 100, 2);

            return [$amount, $type, $percentage];
        }

        $amount = (float) ($data['discount_amount'] ?? 0);

        if ($amount > $subtotal) {
            throw new BusinessException('Discount amount cannot exceed the subtotal.');
        }

        return [$amount, $type, null];
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
            $taxAmount = $this->taxService->calculate($subtotal, $tax)['tax_amount'];

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

    /**
     * Transportation only — Branch is pure reporting metadata (never touches
     * grand_total/journal lines/AR balances), so unlike every other Invoice
     * field it stays editable regardless of Draft/Submitted status. This is
     * the one deliberate exception to assertDraft()'s "Invoice is locked
     * once submitted" rule; corrections that DO touch money still only ever
     * flow through InvoiceChangeRequestService (nominal) or Credit Note.
     * Exists so a Transportation Invoice submitted before Branch was
     * captured (or created with the wrong one) can be corrected/backfilled.
     */
    public function updateBranch(Invoice $invoice, string $branchId): Invoice
    {
        if ($invoice->invoice_type !== InvoiceType::TRANSPORTATION) {
            throw new BusinessException('Only Transportation Invoices support a direct Branch edit.');
        }

        return DB::transaction(function () use ($invoice, $branchId) {
            $this->invoiceRepository->update($invoice, ['branch_id' => $branchId]);

            if ($invoice->accountsReceivable !== null) {
                $this->accountsReceivableService->updateBranch($invoice->accountsReceivable, $branchId);
            }

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'invoice', "Updated Branch on Invoice \"{$invoice->document_number}\".");

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
