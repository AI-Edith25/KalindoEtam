<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Item;
use App\Repositories\AccountsReceivableRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\TaxRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    protected const EAGER = ['customer', 'salesPerson', 'salesOrder', 'salesOrders', 'branch', 'delivery.warehouse', 'deliveries', 'warehouse', 'locationWarehouse', 'items.tax', 'tax', 'termsOfPayment', 'accountsReceivable.receiptEntryItems.receiptEntry.cashAccount', 'creditNotes', 'debitNotes', 'updater'];

    public function __construct(
        protected InvoiceRepository $invoiceRepository,
        protected InvoiceItemRepository $invoiceItemRepository,
        protected DeliveryRepository $deliveryRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountingService $accountingService,
        protected TaxRepository $taxRepository,
        protected TaxService $taxService,
        protected DiscountService $discountService,
        protected AuditLogService $auditLogService,
        protected FifoLayerService $fifoLayerService,
        protected StockLedgerService $stockLedgerService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->invoiceRepository->search($filters, $perPage);
    }

    /** Unpaginated, for bulk export/print — same filters as list(), plus an optional $ids override. */
    public function listAll(array $filters = [], ?array $ids = null): Collection
    {
        return $this->invoiceRepository->searchAll($filters, $ids);
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

        if (! empty($data['warehouse_id'])) {
            return $this->createDirectGoods($data);
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
            // Discount is inherited verbatim from each DeliveryItem (frozen snapshot, same
            // treatment as tax_id/tax_amount below) — never a header input on a Goods invoice.
            $discountAmount = round($deliveries->sum(fn ($delivery) => (float) $delivery->items->sum('discount_amount')), 2);
            // Goods invoices have no single header tax anymore — each line's tax was already
            // resolved (on its own net-of-discount amount) when its Sales Order line/Delivery
            // line was created; this invoice just sums what it copies below.
            $taxAmount = round($deliveries->sum(fn ($delivery) => (float) $delivery->items->sum('tax_amount')), 2);
            $grandTotal = round($subtotal - $discountAmount + $taxAmount, 2);

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $invoice = $this->invoiceRepository->create([
                'delivery_id' => $anchor->id,
                'sales_order_id' => $anchor->sales_order_id,
                'customer_id' => $anchor->customer_id,
                'sales_person_id' => $data['sales_person_id'] ?? $anchor->salesOrder?->sales_person_id,
                // Printed/displayed Location — defaults to the anchor Delivery's own warehouse,
                // independently editable afterward (see Invoice::locationWarehouse()).
                'location_warehouse_id' => $data['location_warehouse_id'] ?? $anchor->warehouse_id,
                // Defaults to Goods — every caller that predates Sprint 2 (Invoice Numbering),
                // including existing tests, never passes this and means Goods either way.
                'invoice_type' => $data['invoice_type'] ?? InvoiceType::GOODS->value,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_type' => DiscountType::AMOUNT->value,
                'discount_percentage' => null,
                'tax_base' => round($subtotal - $discountAmount, 2),
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
                    // Snapshot COGS at invoice-creation time from what this Delivery actually
                    // consumed (FifoLayerService::averageConsumedCost — same call CreditNoteService
                    // uses to reprice a restock) — never recomputed from the item's current cost
                    // later, so a Margin report stays accurate even after prices change.
                    $unitCost = $this->fifoLayerService->averageConsumedCost($line->item_id, StockVoucherType::DELIVERY, $delivery->id);

                    $this->invoiceItemRepository->create([
                        'invoice_id' => $invoice->id,
                        'delivery_item_id' => $line->id,
                        'item_id' => $line->item_id,
                        'item_code' => $line->item_code,
                        'item_name' => $line->item_name,
                        'uom' => $line->uom,
                        'uom_factor' => $line->uom_factor,
                        'rate' => $line->rate,
                        'qty' => $line->qty,
                        'amount' => $line->amount,
                        // Copied verbatim from the DeliveryItem — already resolved upstream,
                        // same frozen-snapshot treatment as item_code/item_name/uom above.
                        'discount_type' => $line->discount_type,
                        'discount_value' => $line->discount_value,
                        'discount_amount' => $line->discount_amount,
                        'net_amount' => $line->net_amount,
                        // unit_cost is per *base* unit (FIFO), qty is in the line's UOM.
                        'unit_cost' => $unitCost,
                        'cost_amount' => round($unitCost * $line->baseQty(), 2),
                        'tax_id' => $line->tax_id,
                        'tax_amount' => $line->tax_amount,
                    ]);
                }
            }

            $invoice->deliveries()->sync($deliveries->pluck('id')->all());
            // filter() drops a Direct Delivery's null sales_order_id before sync() — the pivot's
            // sales_order_id column is NOT NULL/FK, and a Direct Delivery has no Sales Order to link.
            $invoice->salesOrders()->sync($deliveries->pluck('sales_order_id')->filter()->unique()->all());

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
            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxAmountTotal = 0.0;
            $lines = [];

            foreach ($data['items'] as $line) {
                $qty = (float) $line['qty'];
                $rate = (float) $line['rate'];
                $grossAmount = $qty * $rate;
                [$discountType, $discountValue, $discountAmount, $netAmount] = $this->discountService->resolveLineDiscount($line, $grossAmount);
                [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, null, '', $netAmount);

                $subtotal += $grossAmount;
                $discountTotal += $discountAmount;
                $taxAmountTotal += $taxAmount;
                $lines[] = [
                    'line' => $line, 'qty' => $qty, 'rate' => $rate, 'grossAmount' => $grossAmount,
                    'discountType' => $discountType, 'discountValue' => $discountValue, 'discountAmount' => $discountAmount, 'netAmount' => $netAmount,
                    'taxId' => $taxId, 'taxAmount' => $taxAmount,
                ];
            }

            $discountTotal = round($discountTotal, 2);
            $taxAmountTotal = round($taxAmountTotal, 2);
            $grandTotal = round($subtotal - $discountTotal + $taxAmountTotal, 2);

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
                // Printed/displayed Location — no Delivery/warehouse to default from, so purely manual (see Invoice::locationWarehouse()).
                'location_warehouse_id' => $data['location_warehouse_id'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'discount_type' => DiscountType::AMOUNT->value,
                'discount_percentage' => null,
                'tax_base' => round($subtotal - $discountTotal, 2),
                // Header tax_id is no longer meaningful — tax is per-line now, same convention
                // Goods already uses. tax_amount above is the authoritative sum of the lines.
                'tax_id' => null,
                'tax_amount' => $taxAmountTotal,
                'grand_total' => $grandTotal,
                'remarks' => $data['remarks'] ?? null,
                // No Sales Order to derive from — manual entry only (e.g. the related SI number).
                'reference_1' => $data['reference_1'] ?? null,
                'reference_2' => $data['reference_2'] ?? null,
            ]);

            foreach ($lines as $built) {
                $this->invoiceItemRepository->create([
                    'invoice_id' => $invoice->id,
                    'delivery_item_id' => null,
                    'item_id' => null,
                    'item_code' => null,
                    'item_name' => $built['line']['description'],
                    'uom' => $built['line']['uom'] ?? null,
                    'rate' => $built['rate'],
                    'qty' => $built['qty'],
                    'amount' => round($built['grossAmount'], 2),
                    'discount_type' => $built['discountType']->value,
                    'discount_value' => $built['discountValue'],
                    'discount_amount' => $built['discountAmount'],
                    'net_amount' => $built['netAmount'],
                    'tax_id' => $built['taxId'],
                    'tax_amount' => round($built['taxAmount'], 2),
                ]);
            }

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('created', 'invoice', "Created Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /**
     * "Goods (Direct)" — Jumbo & Curah billed straight to a Customer, no Sales Order/Delivery.
     * Still real, physical-goods lines resolved against the Item master (unlike Transportation's
     * free-text description), so unlike Goods/Transportation this is the one Invoice flow that
     * itself reduces stock — but not here: matching every other stock-moving document in this
     * codebase (DeliveryService::complete(), GoodsReceiptService::submit()), the actual
     * StockLedger/FIFO posting happens at submit() (see postDirectGoodsStock()), never at create.
     * invoice_type stays 'goods' on purpose — Invoice::isDirectGoods() (warehouse_id presence)
     * is what distinguishes it from a Delivery-based Goods invoice, so both share the exact same
     * invoice_goods Naming Series.
     */
    protected function createDirectGoods(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $itemsById = Item::query()->with('uom')->whereIn('id', collect($data['items'])->pluck('item_id'))->get()->keyBy('id');

            $subtotal = 0.0;
            $discountTotal = 0.0;
            $taxAmountTotal = 0.0;
            $lines = [];

            foreach ($data['items'] as $line) {
                $item = $itemsById->get($line['item_id']);

                if ($item === null) {
                    throw new BusinessException("Item not found for one of the selected lines.");
                }

                $qty = (float) $line['qty'];
                $rate = (float) $line['rate'];
                $grossAmount = $qty * $rate;
                [$discountType, $discountValue, $discountAmount, $netAmount] = $this->discountService->resolveLineDiscount($line, $grossAmount);
                [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $item, 'sales_tax_id', $netAmount);

                $subtotal += $grossAmount;
                $discountTotal += $discountAmount;
                $taxAmountTotal += $taxAmount;
                $lines[] = [
                    'item' => $item, 'qty' => $qty, 'rate' => $rate, 'amount' => $grossAmount,
                    'discount_type' => $discountType, 'discount_value' => $discountValue, 'discount_amount' => $discountAmount, 'net_amount' => $netAmount,
                    'tax_id' => $taxId, 'tax_amount' => $taxAmount,
                ];
            }

            $discountTotal = round($discountTotal, 2);
            $taxAmountTotal = round($taxAmountTotal, 2);
            $grandTotal = round($subtotal - $discountTotal + $taxAmountTotal, 2);

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $invoice = $this->invoiceRepository->create([
                'delivery_id' => null,
                'sales_order_id' => null,
                'warehouse_id' => $data['warehouse_id'],
                // Printed/displayed Location — defaults to the same warehouse picked above, independently editable afterward (see Invoice::locationWarehouse()).
                'location_warehouse_id' => $data['location_warehouse_id'] ?? $data['warehouse_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'sales_person_id' => $data['sales_person_id'] ?? null,
                'invoice_type' => InvoiceType::GOODS->value,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'discount_type' => DiscountType::AMOUNT->value,
                'discount_percentage' => null,
                'tax_base' => round($subtotal - $discountTotal, 2),
                // Header tax_id has no single meaningful value once tax is per-line — same
                // convention as Delivery-based Goods invoices, see createGoods() above.
                'tax_id' => null,
                'tax_amount' => $taxAmountTotal,
                'grand_total' => $grandTotal,
                'remarks' => $data['remarks'] ?? null,
                'reference_1' => $data['reference_1'] ?? null,
                'reference_2' => $data['reference_2'] ?? null,
            ]);

            foreach ($lines as $line) {
                $item = $line['item'];

                // unit_cost/cost_amount stay unset until submit() — this item's stock hasn't
                // been consumed from any FIFO layer yet, so no cost exists to snapshot.
                $this->invoiceItemRepository->create([
                    'invoice_id' => $invoice->id,
                    'delivery_item_id' => null,
                    'item_id' => $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'uom' => $item->uom?->name,
                    'rate' => $line['rate'],
                    'qty' => $line['qty'],
                    'amount' => $line['amount'],
                    'discount_type' => $line['discount_type']->value,
                    'discount_value' => $line['discount_value'],
                    'discount_amount' => $line['discount_amount'],
                    'net_amount' => $line['net_amount'],
                    'tax_id' => $line['tax_id'],
                    'tax_amount' => $line['tax_amount'],
                ]);
            }

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('created', 'invoice', "Created Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /**
     * Draft: header fields are editable; never delivery_id. Qty/Rate/Tax per line are editable
     * too (applyDraftItemChanges()) — same relaxation Submitted invoices already have
     * (updateSubmitted()), just reachable before submit, with no stock/GL to reverse-and-repost
     * since neither has posted yet at Draft. Transportation keeps using its own freestanding
     * add/remove line editor on create and is never sent `items` here. Submitted: see updateSubmitted().
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        if ($invoice->status === DocumentStatus::SUBMITTED) {
            return $this->updateSubmitted($invoice, $data);
        }

        return DB::transaction(function () use ($invoice, $data) {
            $this->assertDraft($invoice, 'updated');

            if (isset($data['items']) && $invoice->invoice_type === InvoiceType::TRANSPORTATION) {
                throw new BusinessException('Baris Transportation Invoice tidak bisa diubah lewat sini.');
            }

            if (isset($data['items'])) {
                $this->applyDraftItemChanges($invoice, $data['items']);
                $invoice->refresh();
            }

            $itemsChanged = isset($data['items']) && $invoice->invoice_type !== InvoiceType::TRANSPORTATION;

            // Discount and tax are always derived from the lines now (Goods: copied forward from
            // the source Delivery/Sales Order line, or recomputed here when the caller just edited
            // qty/rate/discount/tax per line; Transportation: items are rejected above, so its
            // lines — already resolved at creation — are simply re-summed if nothing changed).
            $subtotal = $itemsChanged ? round((float) $invoice->items()->sum('amount'), 2) : (float) $invoice->subtotal;
            $discountAmount = $itemsChanged ? round((float) $invoice->items()->sum('discount_amount'), 2) : (float) $invoice->discount_amount;
            $taxAmount = $itemsChanged ? round((float) $invoice->items()->sum('tax_amount'), 2) : (float) $invoice->tax_amount;

            $grandTotal = round($subtotal - $discountAmount + $taxAmount, 2);

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $this->invoiceRepository->update($invoice, [
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'terms_of_payment_id' => array_key_exists('terms_of_payment_id', $data) ? $data['terms_of_payment_id'] : $invoice->terms_of_payment_id,
                'location_warehouse_id' => array_key_exists('location_warehouse_id', $data) ? $data['location_warehouse_id'] : $invoice->location_warehouse_id,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_base' => round($subtotal - $discountAmount, 2),
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
     * A stakeholder-driven relaxation, same posture as PurchaseOrderService::updateSubmitted()/
     * SalesOrderService::updateApproved(): a Submitted Invoice used to be fully locked (Cancel ->
     * Create New was the only correction path). Item identity (item_id/item_code/item_name/uom)
     * and Delivery/Sales Order linkage stay locked — no add/remove, see
     * applySubmittedItemChanges(); only qty/rate/tax_id per line and a set of header fields are
     * editable. Any change to grand_total reverses this Invoice's previously-posted Journal Entry
     * and posts a fresh one with the final totals (AccountingService::reverseForDocument()/
     * postForDocument(), both already-generic, already used by Credit Note/Debit Note/Purchase
     * Return) and resizes the existing Accounts Receivable row by the same delta
     * (AccountsReceivableService::adjustForNominalChange() — the exact "resize amount by a delta,
     * recompute UNPAID/PARTIALLY_PAID/PAID" primitive Debit/Credit Note already share). Allowed
     * even once the Invoice has payments/Credit/Debit Notes applied (confirmed with the user) —
     * unlike InvoiceChangeRequestService's own narrower (Transportation-only, Rate-only,
     * blocks-if-paid) nominal-change workflow, which this doesn't touch or reuse beyond its 2
     * proven AR/GL primitives. Transportation invoices keep using that dedicated workflow for
     * money changes — `items` here is rejected for them, header fields still go through.
     */
    protected function updateSubmitted(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('lock_version', $data) && (int) $data['lock_version'] !== $invoice->lock_version) {
                throw new BusinessException('Dokumen ini sudah diubah oleh pengguna lain. Muat ulang halaman dan coba lagi.', 409);
            }

            if (isset($data['items']) && $invoice->invoice_type === InvoiceType::TRANSPORTATION) {
                throw new BusinessException('Gunakan menu "Ubah Nominal" untuk mengubah Rate pada Transportation Invoice.');
            }

            $editableFields = ['invoice_date', 'due_date', 'terms_of_payment_id', 'sales_person_id', 'branch_id', 'attention', 'tel', 'fax', 'reference_1', 'reference_2', 'customer_address', 'customer_phone', 'remarks', 'location_warehouse_id'];
            $before = $invoice->only([...$editableFields, 'discount_amount', 'discount_type', 'discount_percentage', 'subtotal', 'tax_amount', 'grand_total']);
            $headerData = collect($data)->only($editableFields)->all();

            $oldGrandTotal = (float) $invoice->grand_total;

            if (isset($data['items'])) {
                $this->applySubmittedItemChanges($invoice, $data['items']);
                $invoice->refresh();
            }

            $subtotal = round((float) $invoice->items()->sum('amount'), 2);
            $discountAmount = round((float) $invoice->items()->sum('discount_amount'), 2);
            $taxAmount = round((float) $invoice->items()->sum('tax_amount'), 2);
            $grandTotal = round($subtotal - $discountAmount + $taxAmount, 2);

            if ($grandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $headerData['subtotal'] = $subtotal;
            $headerData['tax_amount'] = $taxAmount;
            $headerData['discount_amount'] = $discountAmount;
            $headerData['tax_base'] = round($subtotal - $discountAmount, 2);
            $headerData['grand_total'] = $grandTotal;
            $headerData['lock_version'] = $invoice->lock_version + 1;

            $this->invoiceRepository->update($invoice, $headerData);

            $delta = round($grandTotal - $oldGrandTotal, 2);

            if ($delta !== 0.0 && $invoice->import_source_type === null && $invoice->accountsReceivable !== null) {
                $accountsReceivable = $invoice->accountsReceivable;
                $this->accountsReceivableRepository->lockManyForUpdate([$accountsReceivable->id]);
                $this->accountingService->reverseForDocument($invoice);
                $freshInvoice = $invoice->fresh(['items']);
                $this->accountingService->postForDocument($freshInvoice, $freshInvoice->journalLines(), "Koreksi Invoice {$invoice->document_number}", now()->toDateString());
                $this->accountsReceivableService->adjustForNominalChange($accountsReceivable, $delta);
            }

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->recordChanges('updated', 'invoice', $invoice, $before, $headerData, "Updated submitted Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /**
     * Item identity (item_id/item_code/item_name/uom) and Delivery/Sales Order linkage stay
     * locked — every incoming line must reference an existing InvoiceItem id, no add/remove; only
     * qty/rate/tax_id change. Shared by both a Draft edit (update(), no stock/GL posted yet — see
     * applyDraftItemChanges' own docblock) and a Submitted edit (applySubmittedItemChanges(),
     * which wraps this with the stock/GL side effects its own docblock covers).
     */
    protected function applyItemChanges(Invoice $invoice, array $items): void
    {
        $invoice->load('items');
        $existing = $invoice->items->keyBy('id');
        $incomingIds = collect($items)->pluck('id')->filter()->unique()->values();

        if ($incomingIds->count() !== count($items) || $incomingIds->diff($existing->keys())->isNotEmpty() || $existing->keys()->diff($incomingIds)->isNotEmpty()) {
            throw new BusinessException('Baris Invoice tidak bisa ditambah atau dihapus — hanya Qty, Rate, dan Tax yang bisa diubah.');
        }

        $incomingById = collect($items)->keyBy('id');

        foreach ($existing as $id => $line) {
            $incoming = $incomingById->get($id);
            $qty = (int) round((float) ($incoming['qty'] ?? $line->qty));

            if ($qty <= 0) {
                throw new BusinessException("Qty untuk item \"{$line->item_name}\" harus lebih dari 0.");
            }

            $rate = (float) ($incoming['rate'] ?? $line->rate);
            $amount = round($qty * $rate, 2);

            $discountType = array_key_exists('discount_type', $incoming) ? DiscountType::from($incoming['discount_type']) : DiscountType::from($line->discount_type);
            $discountValue = array_key_exists('discount_value', $incoming) ? (float) $incoming['discount_value'] : (float) $line->discount_value;
            ['discount_amount' => $discountAmount, 'net_amount' => $netAmount] = $this->discountService->calculate($amount, $discountType, $discountValue);

            $taxId = array_key_exists('tax_id', $incoming) ? $incoming['tax_id'] : $line->tax_id;
            $taxAmount = $taxId !== null ? round($this->taxService->calculate($netAmount, $this->taxRepository->findOrFail($taxId))['tax_amount'], 2) : 0.0;

            $attributes = [
                'qty' => $qty, 'rate' => $rate, 'amount' => $amount,
                'discount_type' => $discountType->value, 'discount_value' => $discountValue, 'discount_amount' => $discountAmount, 'net_amount' => $netAmount,
                'tax_id' => $taxId, 'tax_amount' => $taxAmount,
            ];

            if ($line->unit_cost !== null) {
                $attributes['cost_amount'] = round((float) $line->unit_cost * $qty * (float) ($line->uom_factor ?? 1), 2);
            }

            $this->invoiceItemRepository->update($line, $attributes);
        }
    }

    /**
     * Draft counterpart to applySubmittedItemChanges() — no stock/FIFO or GL/AR side effects to
     * reverse-and-repost, since a Draft invoice (Direct Goods included — see
     * postDirectGoodsStock()'s own docblock, "StockLedger/FIFO posting happens at submit(), never
     * at create") has never posted either yet. Just applies the Qty/Rate/Tax change directly.
     */
    protected function applyDraftItemChanges(Invoice $invoice, array $items): void
    {
        $this->applyItemChanges($invoice, $items);
    }

    /**
     * Item identity (item_id/item_code/item_name/uom) and Delivery/Sales Order linkage stay
     * locked — every incoming line must reference an existing InvoiceItem id, no add/remove; only
     * qty/rate/tax_id change (applyItemChanges()). Direct Goods reverses+reposts real stock/FIFO
     * around the edit (reusing postDirectGoodsStock()/reverseDirectGoodsStock() verbatim — no new
     * stock code); every other Goods invoice never touches stock (the source Delivery already
     * did), it just recomputes amount/tax_amount and rescales the frozen COGS snapshot —
     * unit_cost is a per-unit figure and stays valid, only the extended cost_amount needs to
     * track the new qty so Gross Profit/Product Sales reporting doesn't go stale against it.
     */
    protected function applySubmittedItemChanges(Invoice $invoice, array $items): void
    {
        $isDirectGoods = $invoice->isDirectGoods();

        if ($isDirectGoods) {
            $this->reverseDirectGoodsStock($invoice);
        }

        $this->applyItemChanges($invoice, $items);

        if ($isDirectGoods) {
            $this->postDirectGoodsStock($invoice->fresh(['items']));
        }
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

            // Imported historical Invoices (import_source_type set — see SalesInvoiceImportService)
            // never move stock and never post their own GL entry — stock was never really consumed
            // by these rows, and GL's AR control-account balance already comes from the Trial
            // Balance import as one aggregate journal entry (TrialBalanceImportService); posting a
            // per-invoice entry too would double-count it. Status still flips to Submitted above
            // either way, so an imported invoice looks and behaves like a real one everywhere else.
            if ($invoice->import_source_type === null) {
                if ($invoice->isDirectGoods()) {
                    $this->postDirectGoodsStock($invoice);
                }

                $this->accountingService->postForDocument($invoice, $invoice->journalLines(), "Invoice {$invoice->document_number}", $invoice->invoice_date->toDateString());
            }

            // AR *is* always created, even for historical imports — without a real AccountsReceivable
            // row here, a historical invoice can never be found/allocated against by a later Official
            // Receipt (PaymentAllocationService only ever queries this table), leaving real customer
            // payments permanently stuck unallocated. See docs note in SalesInvoiceImportService.
            $this->accountsReceivableService->createFromInvoice($invoice);

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

            if ($invoice->import_source_type === null && $invoice->isDirectGoods()) {
                $this->reverseDirectGoodsStock($invoice);
            }

            $invoice = $invoice->fresh(self::EAGER);
            $this->auditLogService->record('cancelled', 'invoice', "Cancelled Invoice \"{$invoice->document_number}\".");

            return $invoice;
        });
    }

    /**
     * Direct Goods only — the one Invoice flow that itself moves stock. Mirrors
     * DeliveryService::complete()'s shape: validate every line first (all-or-nothing, matching
     * its "block, don't silently corrupt" philosophy), then record. Called from submit(), which
     * has already flipped status to Submitted — Invoice::cancel()'s own guard (only a Submitted
     * document can reach cancel(), see Documentable::cancellableStatuses()) means this always
     * runs exactly once per invoice, never on one that never had stock deducted.
     */
    protected function postDirectGoodsStock(Invoice $invoice): void
    {
        $invoice->load('items');

        foreach ($invoice->items as $line) {
            $this->assertSufficientStock($invoice->warehouse_id, $line->item_id, (float) $line->qty);
        }

        foreach ($invoice->items as $line) {
            $this->stockLedgerService->record(
                itemId: $line->item_id,
                warehouseId: $invoice->warehouse_id,
                transactionType: StockTransactionType::OUT,
                voucherType: StockVoucherType::DIRECT_INVOICE,
                voucherId: $invoice->id,
                qtyChange: -$line->qty,
                postingDatetime: $invoice->invoice_date,
                referenceNo: $invoice->document_number,
                remarks: "Direct Invoice {$invoice->document_number}",
            );

            $result = $this->fifoLayerService->consume($line->item_id, $invoice->warehouse_id, (float) $line->qty, StockVoucherType::DIRECT_INVOICE, $invoice->id);

            $this->invoiceItemRepository->update($line, [
                'unit_cost' => $result->weightedAverageUnitCost,
                'cost_amount' => round($result->totalCost, 2),
            ]);
        }
    }

    /**
     * Undoes postDirectGoodsStock() exactly — mirrors PurchaseReturnService::reverse()'s shape:
     * a reversing IN ledger entry per line (same voucherType/voucherId, the original invoice_date
     * per that same precedent, not now()), then one exact-layer-restore call. See
     * FifoLayerService::reverseConsumption()'s own docblock for why this is safe/idempotent.
     */
    protected function reverseDirectGoodsStock(Invoice $invoice): void
    {
        $invoice->load('items');

        foreach ($invoice->items as $line) {
            $this->stockLedgerService->record(
                itemId: $line->item_id,
                warehouseId: $invoice->warehouse_id,
                transactionType: StockTransactionType::IN,
                voucherType: StockVoucherType::DIRECT_INVOICE,
                voucherId: $invoice->id,
                qtyChange: $line->qty,
                postingDatetime: $invoice->invoice_date,
                referenceNo: $invoice->document_number,
                remarks: "Reversal of Direct Invoice {$invoice->document_number}",
            );
        }

        $this->fifoLayerService->reverseConsumption(StockVoucherType::DIRECT_INVOICE, $invoice->id);
    }

    /** Same shape/message as DeliveryService::assertSufficientStock() — a deliberate small duplication rather than a shared trait for one 5-line check used twice. */
    protected function assertSufficientStock(string $warehouseId, string $itemId, float $qty): void
    {
        $available = $this->stockLedgerService->getCurrentBalance($itemId, $warehouseId);

        if ($qty > $available) {
            throw new BusinessException("Insufficient stock: requested {$qty}, available {$available} in this warehouse.");
        }
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
