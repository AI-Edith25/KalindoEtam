<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseInvoiceSource;
use App\Exceptions\BusinessException;
use App\Models\ChartOfAccount;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Repositories\ChartOfAccountRepository;
use App\Repositories\PurchaseInvoiceItemRepository;
use App\Repositories\PurchaseInvoiceRepository;
use App\Repositories\SupplierRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    protected const EAGER = ['supplier', 'purchaseOrder', 'purchaseOrders', 'goodsReceipt.warehouse', 'goodsReceipts', 'items.item', 'items.chartOfAccount', 'accountsPayable', 'purchaseReturns'];

    public function __construct(
        protected PurchaseInvoiceRepository $purchaseInvoiceRepository,
        protected PurchaseInvoiceItemRepository $purchaseInvoiceItemRepository,
        protected SupplierRepository $supplierRepository,
        protected ChartOfAccountRepository $chartOfAccountRepository,
        protected TaxService $taxService,
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
     * Builds whichever of a stock-line group (from goods_receipt_ids) and a non-stock-line group
     * (from items) are present, derives goods_receipt/direct/mixed from the result, and combines
     * their totals into one invoice. Supplier is always chosen up front now (the editor's Step 1,
     * before Goods Receipt selection), so it's always present in $data regardless of which groups
     * end up populated.
     */
    public function create(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data) {
            $supplier = $this->supplierRepository->findOrFail($data['supplier_id']);

            $goodsReceiptIds = $data['goods_receipt_ids'] ?? [];
            $directItems = $data['items'] ?? [];

            if (empty($goodsReceiptIds) && empty($directItems)) {
                throw new BusinessException('Invoice must have at least one line item.');
            }

            [$stockLines, $goodsReceipts] = ! empty($goodsReceiptIds)
                ? $this->buildStockLines($goodsReceiptIds, $supplier, $data['item_rates'] ?? [])
                : [[], collect()];

            $nonStockLines = ! empty($directItems) ? $this->buildDirectLines($directItems) : [];

            $source = match (true) {
                ! empty($stockLines) && ! empty($nonStockLines) => PurchaseInvoiceSource::MIXED,
                ! empty($stockLines) => PurchaseInvoiceSource::GOODS_RECEIPT,
                default => PurchaseInvoiceSource::DIRECT,
            };

            $stockSubtotal = round((float) array_sum(array_column($stockLines, 'amount')), 2);
            $nonStockSubtotal = round((float) array_sum(array_column($nonStockLines, 'amount')), 2);
            $subtotal = round($stockSubtotal + $nonStockSubtotal, 2);

            // Header tax_amount covers only the stock portion (manual — Goods Receipt items carry
            // no tax snapshot); non-stock lines each resolve and store their own tax_amount in
            // buildDirectLines(), summed here into the one combined figure this invoice stores.
            $headerTaxAmount = (float) ($data['tax_amount'] ?? 0);
            $lineTaxAmount = round((float) array_sum(array_column($nonStockLines, 'tax_amount')), 2);
            $taxAmount = round($headerTaxAmount + $lineTaxAmount, 2);
            $grandTotal = $subtotal + $taxAmount;
            $this->assertPositiveGrandTotal($grandTotal);

            $dueDate = $this->resolveDueDate($data, $supplier->id, $data['invoice_date']);
            $anchor = $goodsReceipts->first();

            $purchaseInvoice = $this->purchaseInvoiceRepository->create([
                'source' => $source,
                'goods_receipt_id' => $anchor?->id,
                'purchase_order_id' => $anchor?->purchase_order_id,
                'supplier_id' => $supplier->id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'reference_number' => $data['reference_number'] ?? null,
                'attention' => $data['attention'] ?? null,
                'department' => $data['department'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ([...$stockLines, ...$nonStockLines] as $line) {
                $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
            }

            if ($goodsReceipts->isNotEmpty()) {
                $purchaseInvoice->goodsReceipts()->sync($goodsReceipts->pluck('id')->all());
                // A Direct Receipt (no source PO) has a null purchase_order_id — filter it out
                // before sync(), otherwise the pivot insert fails its FK constraint on a null id.
                $purchaseInvoice->purchaseOrders()->sync($goodsReceipts->pluck('purchase_order_id')->filter()->unique()->all());
            }

            $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
            $this->auditLogService->record('created', 'purchase_invoice', "Created Purchase Invoice \"{$purchaseInvoice->document_number}\".");

            return $purchaseInvoice;
        });
    }

    /**
     * Row-locks each Goods Receipt for the rest of this transaction, then reloads its relations
     * fresh (not from any pre-lock snapshot) — closes a race where two concurrent requests
     * invoicing the same Goods Receipt could otherwise both pass the "not yet invoiced" check
     * below before either's purchase_invoice_goods_receipts row actually commits, crashing the
     * second one on that pivot's unique constraint instead of getting a clean "already invoiced"
     * message. Every selected Goods Receipt must belong to $supplier — the Supplier chosen in
     * Step 1 of the editor, before this Goods Receipt list was ever shown.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: \Illuminate\Support\Collection}
     */
    protected function buildStockLines(array $goodsReceiptIds, Supplier $supplier, array $itemRates): array
    {
        $goodsReceipts = collect($goodsReceiptIds)
            ->map(fn (string $id) => GoodsReceipt::query()->lockForUpdate()->findOrFail($id)->load(['supplier', 'purchaseOrder', 'items', 'purchaseInvoices']))
            ->sortBy(fn ($goodsReceipt) => [$goodsReceipt->receipt_date, $goodsReceipt->id])
            ->values();

        foreach ($goodsReceipts as $goodsReceipt) {
            if ($goodsReceipt->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} must be submitted before it can be invoiced.");
            }

            if ($goodsReceipt->purchaseInvoices->isNotEmpty()) {
                throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} has already been invoiced.");
            }

            if ($goodsReceipt->supplier_id !== $supplier->id) {
                throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} does not belong to the selected Supplier.");
            }
        }

        // Rate overrides keyed by the source Goods Receipt item — see StorePurchaseInvoiceRequest's
        // own comment. Qty/item identity always come from the Goods Receipt line untouched.
        $rateOverrides = collect($itemRates)->pluck('rate', 'goods_receipt_item_id');

        $lines = $goodsReceipts->flatMap(fn ($goodsReceipt) => $goodsReceipt->items)->map(function ($line) use ($rateOverrides) {
            $rate = $rateOverrides->has($line->id) ? (float) $rateOverrides->get($line->id) : (float) $line->rate;

            return [
                'goods_receipt_item_id' => $line->id,
                'item_id' => $line->item_id,
                'item_code' => $line->item_code,
                'item_name' => $line->item_name,
                'uom' => $line->uom,
                'uom_factor' => $line->uom_factor,
                'rate' => $rate,
                'qty' => $line->qty,
                'amount' => round((float) $line->qty * $rate, 2),
            ];
        })->all();

        return [$lines, $goodsReceipts];
    }

    /**
     * @return array<int, array{chart_of_account_id: string, item_name: string, uom: ?string, qty: float, rate: float, amount: float, tax_id: ?string, tax_amount: float}>
     */
    protected function buildDirectLines(array $items): array
    {
        return collect($items)->map(function (array $line) {
            $account = $this->resolveDirectLineAccount($line['chart_of_account_id']);
            $qty = (float) $line['qty'];
            $rate = (float) $line['rate'];
            $amount = round($qty * $rate, 2);
            [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, null, '', $amount);

            return [
                'chart_of_account_id' => $account->id,
                'item_name' => $line['description'],
                'uom' => $line['uom'] ?? null,
                'qty' => $qty,
                'rate' => $rate,
                'amount' => $amount,
                'tax_id' => $taxId,
                'tax_amount' => $taxAmount,
            ];
        })->all();
    }

    protected function assertPositiveGrandTotal(float $grandTotal): void
    {
        if ($grandTotal <= 0) {
            throw new BusinessException('Grand Total must be greater than zero.');
        }
    }

    /**
     * Non-stock lines post to an Expense account (the common case — vehicle repairs, services,
     * etc.) or a non-cash/bank Asset account (e.g. Uang Muka Pembelian / advance to supplier).
     * Liability and Equity/Revenue accounts are deliberately excluded — the only Liability use
     * case this ever supported (a withholding-tax deduction line) was removed from the UI and is
     * no longer selectable. Cash/Bank is Asset-typed but excluded here since it's never an
     * appropriate account for an invoice line.
     */
    protected function resolveDirectLineAccount(string $chartOfAccountId): ChartOfAccount
    {
        $account = $this->chartOfAccountRepository->findOrFail($chartOfAccountId);
        $isAcceptableAsset = $account->account_type === AccountType::ASSET && ! $account->is_cash_bank;

        if ((! $isAcceptableAsset && $account->account_type !== AccountType::EXPENSE) || ! $account->is_active) {
            throw new BusinessException("Account {$account->code} — {$account->name} is not an active Expense or Asset (non-cash/bank) account.");
        }

        return $account;
    }

    /**
     * Terms of Payment live on the Supplier, not the invoice — due date is invoice_date + the
     * Supplier's TOP days (no TOP = due on invoice date). An explicit due_date still wins.
     * Mirrors GoodsReceiptService::resolveDueDate() — small enough (and only 2 call sites
     * across the codebase) that sharing it isn't worth a cross-service abstraction.
     */
    protected function resolveDueDate(array $data, string $supplierId, string $invoiceDate): string
    {
        if (! empty($data['due_date'])) {
            return $data['due_date'];
        }

        $days = Supplier::query()->with('termsOfPayment')->find($supplierId)?->termsOfPayment?->days ?? 0;

        return Carbon::parse($invoiceDate)->addDays($days)->toDateString();
    }

    /**
     * Stock lines are immutable post-create (never gained/lost via update, only Rate-overridden
     * in place via item_rates); non-stock lines can be fully replaced via items. Both can be
     * touched in the same call — recomputes totals and re-derives source from whatever line mix
     * results. due_date is preserved when omitted rather than re-derived from the Supplier's
     * Terms of Payment (that auto-derivation only applies at create() time) — an unrelated field
     * edit should never silently move a previously-set due date.
     */
    public function update(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseInvoice, $data) {
            $this->assertDraft($purchaseInvoice, 'updated');

            if (isset($data['item_rates'])) {
                $this->applyRateOverrides($purchaseInvoice, $data['item_rates']);
            }

            if (isset($data['items'])) {
                $this->replaceNonStockLines($purchaseInvoice, $data['items']);
            }

            $purchaseInvoice->unsetRelation('items');
            $items = $purchaseInvoice->items;

            if ($items->isEmpty()) {
                throw new BusinessException('Invoice must have at least one line item.');
            }

            $hasStockLines = $items->contains(fn ($item) => $item->goods_receipt_item_id !== null);
            $hasNonStockLines = $items->contains(fn ($item) => $item->goods_receipt_item_id === null);
            $source = match (true) {
                $hasStockLines && $hasNonStockLines => PurchaseInvoiceSource::MIXED,
                $hasStockLines => PurchaseInvoiceSource::GOODS_RECEIPT,
                default => PurchaseInvoiceSource::DIRECT,
            };

            $stockSubtotal = round((float) $items->whereNotNull('goods_receipt_item_id')->sum('amount'), 2);
            $nonStockSubtotal = round((float) $items->whereNull('goods_receipt_item_id')->sum('amount'), 2);
            $subtotal = round($stockSubtotal + $nonStockSubtotal, 2);

            $headerTaxAmount = (float) ($data['tax_amount'] ?? 0);
            $lineTaxAmount = round((float) $items->whereNull('goods_receipt_item_id')->sum('tax_amount'), 2);
            $taxAmount = round($headerTaxAmount + $lineTaxAmount, 2);
            $grandTotal = $subtotal + $taxAmount;
            $this->assertPositiveGrandTotal($grandTotal);

            // supplier_id can only change while there are no stock lines — a stock line's
            // ownership was fixed at create() time by the Goods Receipt it came from.
            $supplierId = $hasStockLines ? $purchaseInvoice->supplier_id : ($data['supplier_id'] ?? $purchaseInvoice->supplier_id);
            $invoiceDate = $data['invoice_date'] ?? $purchaseInvoice->invoice_date->toDateString();
            $dueDate = $data['due_date'] ?? $purchaseInvoice->due_date->toDateString();

            $this->purchaseInvoiceRepository->update($purchaseInvoice, [
                'source' => $source,
                'supplier_id' => $supplierId,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'reference_number' => array_key_exists('reference_number', $data) ? $data['reference_number'] : $purchaseInvoice->reference_number,
                'attention' => array_key_exists('attention', $data) ? $data['attention'] : $purchaseInvoice->attention,
                'department' => array_key_exists('department', $data) ? $data['department'] : $purchaseInvoice->department,
                'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $purchaseInvoice->remarks,
            ]);

            $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'purchase_invoice', "Updated Purchase Invoice \"{$purchaseInvoice->document_number}\".");

            return $purchaseInvoice;
        });
    }

    protected function applyRateOverrides(PurchaseInvoice $purchaseInvoice, array $itemRates): void
    {
        $rateOverrides = collect($itemRates)->pluck('rate', 'goods_receipt_item_id');

        foreach ($purchaseInvoice->items as $item) {
            if ($item->goods_receipt_item_id === null || ! $rateOverrides->has($item->goods_receipt_item_id)) {
                continue;
            }

            $rate = (float) $rateOverrides->get($item->goods_receipt_item_id);
            $this->purchaseInvoiceItemRepository->update($item, [
                'rate' => $rate,
                'amount' => round((float) $item->qty * $rate, 2),
            ]);
        }
    }

    /** Full delete+recreate of non-stock lines only — stock lines (goods_receipt_item_id set) are untouched. */
    protected function replaceNonStockLines(PurchaseInvoice $purchaseInvoice, array $items): void
    {
        $purchaseInvoice->items()->whereNull('goods_receipt_item_id')->delete();

        foreach ($this->buildDirectLines($items) as $line) {
            $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
        }
    }

    /**
     * Detaches goodsReceipts()/purchaseOrders() before the (soft) delete — PurchaseInvoice uses
     * SoftDeletes, so the pivot tables' cascadeOnDelete on purchase_invoice_id never actually
     * fires (no real row DELETE ever happens), leaving a stale purchase_invoice_goods_receipts
     * row forever otherwise. Since goods_receipt_id is unique on that pivot ("a Goods Receipt can
     * never be invoiced twice"), that stale row then crashes the next create() for the same
     * Goods Receipt with a raw duplicate-key error instead of a clean "already invoiced" message.
     */
    public function delete(PurchaseInvoice $purchaseInvoice): void
    {
        DB::transaction(function () use ($purchaseInvoice) {
            $this->assertDraft($purchaseInvoice, 'deleted');
            $documentNumber = $purchaseInvoice->document_number;
            $purchaseInvoice->goodsReceipts()->detach();
            $purchaseInvoice->purchaseOrders()->detach();
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
