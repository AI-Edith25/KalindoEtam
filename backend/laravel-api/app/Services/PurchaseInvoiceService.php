<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseInvoiceSource;
use App\Exceptions\BusinessException;
use App\Models\ChartOfAccount;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Repositories\ChartOfAccountRepository;
use App\Repositories\GoodsReceiptRepository;
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
        protected GoodsReceiptRepository $goodsReceiptRepository,
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
            $source = isset($data['source']) ? PurchaseInvoiceSource::from($data['source']) : PurchaseInvoiceSource::GOODS_RECEIPT;

            return $source === PurchaseInvoiceSource::DIRECT
                ? $this->createDirect($data)
                : $this->createFromGoodsReceipts($data);
        });
    }

    protected function createFromGoodsReceipts(array $data): PurchaseInvoice
    {
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
            'source' => PurchaseInvoiceSource::GOODS_RECEIPT,
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
    }

    /**
     * No Goods Receipt/Purchase Order/stock involved at all — lines post straight to a
     * Chart-of-Accounts expense account (vehicle repairs, services, etc.). Never touches
     * GoodsReceiptRepository, StockLedgerService or FifoLayerService.
     */
    protected function createDirect(array $data): PurchaseInvoice
    {
        $supplier = $this->supplierRepository->findOrFail($data['supplier_id']);
        $dueDate = $this->resolveDueDate($data, $supplier->id, $data['invoice_date']);

        $lines = $this->buildDirectLines($data['items']);
        $subtotal = round((float) array_sum(array_column($lines, 'amount')), 2);
        $taxAmount = round((float) array_sum(array_column($lines, 'tax_amount')), 2);

        $purchaseInvoice = $this->purchaseInvoiceRepository->create([
            'source' => PurchaseInvoiceSource::DIRECT,
            'goods_receipt_id' => null,
            'purchase_order_id' => null,
            'supplier_id' => $supplier->id,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $dueDate,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'grand_total' => $subtotal + $taxAmount,
            'reference_number' => $data['reference_number'] ?? null,
            'attention' => $data['attention'] ?? null,
            'department' => $data['department'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);

        foreach ($lines as $line) {
            $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
        }

        $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
        $this->auditLogService->record('created', 'purchase_invoice', "Created Purchase Invoice \"{$purchaseInvoice->document_number}\".");

        return $purchaseInvoice;
    }

    /**
     * @return array<int, array{chart_of_account_id: string, item_name: string, uom: ?string, qty: float, rate: float, amount: float, tax_id: ?string, tax_amount: float}>
     */
    protected function buildDirectLines(array $items): array
    {
        return collect($items)->map(function (array $line) {
            $account = $this->resolveExpenseAccount($line['chart_of_account_id']);
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

    protected function resolveExpenseAccount(string $chartOfAccountId): ChartOfAccount
    {
        $account = $this->chartOfAccountRepository->findOrFail($chartOfAccountId);

        if ($account->account_type !== AccountType::EXPENSE || ! $account->is_active) {
            throw new BusinessException("Account {$account->code} — {$account->name} is not an active Expense account.");
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
     * A Goods Receipt-sourced invoice only has header fields editable — never goods_receipt_id,
     * never items (immutable once created from a Goods Receipt, same posture as
     * Invoice::update()). A Direct invoice additionally allows a full items replace, since it
     * has no source document to stay in sync with.
     */
    public function update(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseInvoice, $data) {
            $this->assertDraft($purchaseInvoice, 'updated');

            return $purchaseInvoice->source === PurchaseInvoiceSource::DIRECT
                ? $this->updateDirect($purchaseInvoice, $data)
                : $this->updateFromGoodsReceipts($purchaseInvoice, $data);
        });
    }

    protected function updateFromGoodsReceipts(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
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
    }

    protected function updateDirect(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
        $supplierId = $data['supplier_id'] ?? $purchaseInvoice->supplier_id;
        $invoiceDate = $data['invoice_date'] ?? $purchaseInvoice->invoice_date->toDateString();
        $dueDate = $this->resolveDueDate($data, $supplierId, $invoiceDate);

        $subtotal = (float) $purchaseInvoice->subtotal;
        $taxAmount = (float) $purchaseInvoice->tax_amount;

        if (isset($data['items'])) {
            $lines = $this->buildDirectLines($data['items']);
            $subtotal = round((float) array_sum(array_column($lines, 'amount')), 2);
            $taxAmount = round((float) array_sum(array_column($lines, 'tax_amount')), 2);

            $purchaseInvoice->items()->delete();

            foreach ($lines as $line) {
                $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
            }
        }

        $this->purchaseInvoiceRepository->update($purchaseInvoice, [
            'supplier_id' => $supplierId,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'grand_total' => $subtotal + $taxAmount,
            'reference_number' => array_key_exists('reference_number', $data) ? $data['reference_number'] : $purchaseInvoice->reference_number,
            'attention' => array_key_exists('attention', $data) ? $data['attention'] : $purchaseInvoice->attention,
            'department' => array_key_exists('department', $data) ? $data['department'] : $purchaseInvoice->department,
            'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $purchaseInvoice->remarks,
        ]);

        $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
        $this->auditLogService->record('updated', 'purchase_invoice', "Updated Purchase Invoice \"{$purchaseInvoice->document_number}\".");

        return $purchaseInvoice;
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
