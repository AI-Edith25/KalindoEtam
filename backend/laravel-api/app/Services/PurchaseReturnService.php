<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseReturnReason;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Exceptions\BusinessException;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Repositories\AccountsPayableRepository;
use App\Repositories\PurchaseInvoiceItemRepository;
use App\Repositories\PurchaseInvoiceRepository;
use App\Repositories\PurchaseReturnItemRepository;
use App\Repositories\PurchaseReturnRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only accounting-correction path for a submitted Purchase Invoice —
 * see PurchaseInvoiceService::cancel(), which deliberately never touches
 * the ledger or stock. Unlike Sales' CreditNote (whose restock flag is
 * intent-only and never wired), a Purchase Return always moves real stock
 * for any line with qty_returned > 0 — see submit()/reverse(). Mirrors
 * CreditNoteService.
 */
class PurchaseReturnService
{
    protected const EAGER = ['purchaseInvoice.goodsReceipt', 'supplier', 'items.purchaseInvoiceItem', 'items.item'];

    public function __construct(
        protected PurchaseReturnRepository $purchaseReturnRepository,
        protected PurchaseReturnItemRepository $purchaseReturnItemRepository,
        protected PurchaseInvoiceRepository $purchaseInvoiceRepository,
        protected PurchaseInvoiceItemRepository $purchaseInvoiceItemRepository,
        protected AccountsPayableRepository $accountsPayableRepository,
        protected AccountsPayableService $accountsPayableService,
        protected AccountingService $accountingService,
        protected StockLedgerService $stockLedgerService,
        protected AuditLogService $auditLogService,
        protected QtyCategoryValidator $qtyCategoryValidator,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->purchaseReturnRepository->search($filters, $perPage);
    }

    /** Unpaginated, same filters as list() — for export. */
    public function listAll(array $filters): Collection
    {
        return $this->purchaseReturnRepository->searchAll($filters);
    }

    public function create(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data) {
            $purchaseInvoice = $this->purchaseInvoiceRepository->findOrFail($data['purchase_invoice_id']);
            $lines = $data['items'] ?? [];

            [$subtotal, $totalAmount] = $this->validateAgainstPurchaseInvoice($purchaseInvoice, $lines, $data);

            // validateAgainstPurchaseInvoice() rounds each line's qty_returned to its item's
            // qty_category in place (by reference) — replaceLines() below relies on that.
            $purchaseReturn = $this->purchaseReturnRepository->create([
                'purchase_invoice_id' => $purchaseInvoice->id,
                'supplier_id' => $purchaseInvoice->supplier_id,
                'return_date' => $data['return_date'],
                'reason' => $data['reason'],
                'subtotal' => $subtotal,
                'tax_amount' => $data['tax_amount'] ?? 0,
                'total_amount' => $totalAmount,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->replaceLines($purchaseReturn, $lines);

            $purchaseReturn = $purchaseReturn->fresh(self::EAGER);
            $this->auditLogService->record('created', 'purchase_return', "Created Purchase Return \"{$purchaseReturn->document_number}\".");

            return $purchaseReturn;
        });
    }

    public function update(PurchaseReturn $purchaseReturn, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn, $data) {
            $this->assertDraft($purchaseReturn, 'updated');

            $purchaseInvoice = $purchaseReturn->purchaseInvoice;
            $lines = $data['items'] ?? $purchaseReturn->items->map(fn ($line) => [
                'purchase_invoice_item_id' => $line->purchase_invoice_item_id,
                'qty_returned' => $line->qty_returned,
                'amount' => (float) $line->amount,
            ])->all();

            $mergedData = [
                'reason' => $data['reason'] ?? $purchaseReturn->reason->value,
                'tax_amount' => $data['tax_amount'] ?? $purchaseReturn->tax_amount,
            ];

            [$subtotal, $totalAmount] = $this->validateAgainstPurchaseInvoice($purchaseInvoice, $lines, $mergedData);

            $this->purchaseReturnRepository->update($purchaseReturn, [
                'return_date' => $data['return_date'] ?? $purchaseReturn->return_date,
                'reason' => $mergedData['reason'],
                'subtotal' => $subtotal,
                'tax_amount' => $mergedData['tax_amount'],
                'total_amount' => $totalAmount,
                'remarks' => $data['remarks'] ?? $purchaseReturn->remarks,
            ]);

            if (isset($data['items'])) {
                $purchaseReturn->items()->delete();
                $this->replaceLines($purchaseReturn, $lines);
            }

            $purchaseReturn = $purchaseReturn->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'purchase_return', "Updated Purchase Return \"{$purchaseReturn->document_number}\".");

            return $purchaseReturn;
        });
    }

    public function delete(PurchaseReturn $purchaseReturn): void
    {
        DB::transaction(function () use ($purchaseReturn) {
            $this->assertDraft($purchaseReturn, 'deleted');
            $documentNumber = $purchaseReturn->document_number;
            $this->purchaseReturnRepository->delete($purchaseReturn);
            $this->auditLogService->record('deleted', 'purchase_return', "Deleted Purchase Return \"{$documentNumber}\".");
        });
    }

    /**
     * Locks the Purchase Invoice's AccountsPayable row before re-validating
     * — two concurrent Returns against the same Invoice cannot both pass
     * validation against a stale credited_amount and jointly over-return
     * it. Same convention as PaymentEntryAllocationService::allocateBatch()
     * and CreditNoteService::submit(). After posting, every line with
     * qty_returned > 0 reverses the stock the originating Goods Receipt
     * added — the ticket's explicit inventory requirement, and the one
     * place PurchaseReturnService actually diverges from CreditNoteService
     * (whose restock flag is never wired).
     */
    public function submit(PurchaseReturn $purchaseReturn): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn) {
            $this->assertDraft($purchaseReturn, 'submitted');

            $accountsPayable = $this->accountsPayableRepository
                ->lockManyForUpdate([$purchaseReturn->purchaseInvoice->accountsPayable->id])
                ->firstOrFail();

            $this->accountsPayableService->assertWithinCreditableBalance($accountsPayable, (float) $purchaseReturn->total_amount);

            $purchaseReturn->submit();

            $this->accountsPayableService->writeDown($accountsPayable, (float) $purchaseReturn->total_amount);

            $this->accountingService->postForDocument(
                $purchaseReturn,
                $purchaseReturn->journalLines(),
                "Purchase Return {$purchaseReturn->document_number} for Purchase Invoice {$purchaseReturn->purchaseInvoice->document_number}",
                $purchaseReturn->return_date->toDateString(),
            );

            foreach ($purchaseReturn->items as $line) {
                if ($line->qty_returned > 0) {
                    $this->stockLedgerService->record(
                        itemId: $line->item_id,
                        warehouseId: $line->warehouse_id,
                        transactionType: StockTransactionType::OUT,
                        voucherType: StockVoucherType::PURCHASE_RETURN,
                        voucherId: $purchaseReturn->id,
                        qtyChange: -$line->qty_returned,
                        postingDatetime: now(),
                        referenceNo: $purchaseReturn->document_number,
                        remarks: "Purchase Return {$purchaseReturn->document_number}",
                    );
                }
            }

            $purchaseReturn = $purchaseReturn->fresh(self::EAGER);
            $this->auditLogService->record('submitted', 'purchase_return', "Submitted Purchase Return \"{$purchaseReturn->document_number}\".");

            return $purchaseReturn;
        });
    }

    /**
     * Undoes a submitted Purchase Return — restores the Invoice's
     * returnable balance, reverses the posted journal, and puts back every
     * unit of stock the submit moved out. The original journal entry is
     * never mutated, only linked to its reversal. Mirrors
     * CreditNoteService::reverse(), plus the stock leg CreditNote doesn't need.
     */
    public function reverse(PurchaseReturn $purchaseReturn): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn) {
            if ($purchaseReturn->status !== DocumentStatus::SUBMITTED || $purchaseReturn->is_reversed) {
                throw new BusinessException('Only a submitted, not-yet-reversed Purchase Return can be reversed.');
            }

            $accountsPayable = $this->accountsPayableRepository
                ->lockManyForUpdate([$purchaseReturn->purchaseInvoice->accountsPayable->id])
                ->firstOrFail();

            $this->accountsPayableService->restoreWriteDown($accountsPayable, (float) $purchaseReturn->total_amount);
            $this->accountingService->reverseForDocument($purchaseReturn);

            foreach ($purchaseReturn->items as $line) {
                if ($line->qty_returned > 0) {
                    $this->stockLedgerService->record(
                        itemId: $line->item_id,
                        warehouseId: $line->warehouse_id,
                        transactionType: StockTransactionType::IN,
                        voucherType: StockVoucherType::PURCHASE_RETURN,
                        voucherId: $purchaseReturn->id,
                        qtyChange: $line->qty_returned,
                        postingDatetime: now(),
                        referenceNo: $purchaseReturn->document_number,
                        remarks: "Reversal of Purchase Return {$purchaseReturn->document_number}",
                    );
                }
            }

            $this->purchaseReturnRepository->update($purchaseReturn, ['is_reversed' => true, 'reversed_at' => now()]);

            $purchaseReturn = $purchaseReturn->fresh(self::EAGER);
            $this->auditLogService->record('reversed', 'purchase_return', "Reversed Purchase Return \"{$purchaseReturn->document_number}\".");

            return $purchaseReturn;
        });
    }

    /**
     * @param  array<int, array{purchase_invoice_item_id: string, qty_returned?: float, amount: float}>  $lines
     * @return array{0: float, 1: float} [subtotal, total_amount]
     */
    protected function validateAgainstPurchaseInvoice(PurchaseInvoice $purchaseInvoice, array &$lines, array $data): array
    {
        if ($purchaseInvoice->status !== DocumentStatus::SUBMITTED) {
            throw new BusinessException('Purchase Returns can only be raised against a submitted Purchase Invoice.');
        }

        $accountsPayable = $purchaseInvoice->accountsPayable;
        if ($accountsPayable === null) {
            throw new BusinessException('This Purchase Invoice has no Accounts Payable to return against.');
        }

        $this->assertNoDuplicateReferences($lines);

        $subtotal = 0.0;
        foreach ($lines as &$line) {
            $invoiceItem = $this->purchaseInvoiceItemRepository->findOrFail($line['purchase_invoice_item_id']);

            if ($invoiceItem->purchase_invoice_id !== $purchaseInvoice->id) {
                throw new BusinessException('One or more lines do not belong to the selected Purchase Invoice.');
            }

            $qtyReturned = (float) ($line['qty_returned'] ?? 0);
            $amount = (float) $line['amount'];

            if ($qtyReturned < 0 || $amount < 0) {
                throw new BusinessException('Returned quantity and amount cannot be negative.');
            }

            if ($qtyReturned > 0) {
                $this->qtyCategoryValidator->assertValid($invoiceItem->item, $qtyReturned);
                $qtyReturned = $this->qtyCategoryValidator->round($invoiceItem->item, $qtyReturned);
                $line['qty_returned'] = $qtyReturned;
            }

            $priorTotals = $this->purchaseReturnItemRepository->returnedTotalsForPurchaseInvoiceItem($invoiceItem->id);

            // Hard ceiling regardless of qty_category — unlike Goods Receipt's PO-estimate-vs-
            // truck-scale gap, this is actual-vs-actual (the Invoice already carries the real
            // received qty for Weight items, copied verbatim from the Goods Receipt), so there's
            // no legitimate reason to return more than was ever invoiced. The 1e-4 epsilon only
            // absorbs float rounding at the qty_category's own decimal precision, same as
            // QtyCategoryValidator::isWholeNumber — it never widens the real boundary.
            $remainingQty = (float) $invoiceItem->qty - $priorTotals['qty'];
            if ($qtyReturned > $remainingQty + 0.0001) {
                throw new BusinessException("Returned quantity ({$qtyReturned}) exceeds what remains returnable ({$remainingQty}) for {$invoiceItem->item_name}.");
            }

            $remainingAmount = (float) $invoiceItem->amount - $priorTotals['amount'];
            if ($amount > $remainingAmount + 0.01) {
                throw new BusinessException("Returned amount ({$amount}) exceeds what remains returnable ({$remainingAmount}) for {$invoiceItem->item_name}.");
            }

            $subtotal += $amount;
        }
        unset($line);

        $taxAmount = (float) ($data['tax_amount'] ?? 0);
        $totalAmount = $subtotal + $taxAmount;

        if ($totalAmount <= 0) {
            throw new BusinessException('Purchase Return total must be greater than zero.');
        }

        $priorReturned = $this->purchaseReturnRepository->creditedTotalForInvoice($purchaseInvoice->id);
        $remainingBalance = (float) $purchaseInvoice->grand_total - $priorReturned;

        if ($totalAmount > $remainingBalance) {
            throw new BusinessException("Purchase Return total ({$totalAmount}) exceeds the Invoice's remaining returnable balance ({$remainingBalance}).");
        }

        return [$subtotal, $totalAmount];
    }

    protected function replaceLines(PurchaseReturn $purchaseReturn, array $lines): void
    {
        foreach ($lines as $line) {
            $invoiceItem = $this->purchaseInvoiceItemRepository->findOrFail($line['purchase_invoice_item_id']);
            $warehouseId = $invoiceItem->goodsReceiptItem->goodsReceipt->warehouse_id;

            $this->purchaseReturnItemRepository->create([
                'purchase_return_id' => $purchaseReturn->id,
                'purchase_invoice_item_id' => $invoiceItem->id,
                'item_id' => $invoiceItem->item_id,
                'warehouse_id' => $warehouseId,
                'item_code' => $invoiceItem->item_code,
                'item_name' => $invoiceItem->item_name,
                'uom' => $invoiceItem->uom,
                'qty_returned' => $line['qty_returned'] ?? 0,
                'qty_category' => $invoiceItem->item->qty_category,
                'rate' => $invoiceItem->rate,
                'amount' => $line['amount'],
            ]);
        }
    }

    protected function assertNoDuplicateReferences(array $lines): void
    {
        $ids = array_column($lines, 'purchase_invoice_item_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new BusinessException('The same Purchase Invoice line cannot appear more than once in a single Purchase Return.');
        }
    }

    protected function assertDraft(PurchaseReturn $purchaseReturn, string $action): void
    {
        if ($purchaseReturn->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Purchase Returns can be {$action}.");
        }
    }
}
