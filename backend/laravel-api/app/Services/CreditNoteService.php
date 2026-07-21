<?php

namespace App\Services;

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Repositories\AccountsReceivableRepository;
use App\Repositories\CreditNoteItemRepository;
use App\Repositories\CreditNoteRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\InvoiceRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The only accounting-correction path for a submitted Invoice — see
 * InvoiceService::cancel(), which deliberately never touches the ledger.
 * No inventory restocking this sprint (Sprint 13B decision): a
 * `restock = true` line records intent only, surfaced in the UI as
 * "Pending Inventory Return Module." See docs/CREDIT_NOTE_DESIGN.md.
 */
class CreditNoteService
{
    protected const EAGER = ['invoice.delivery', 'customer', 'items.invoiceItem', 'items.item'];

    public function __construct(
        protected CreditNoteRepository $creditNoteRepository,
        protected CreditNoteItemRepository $creditNoteItemRepository,
        protected InvoiceRepository $invoiceRepository,
        protected InvoiceItemRepository $invoiceItemRepository,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountingService $accountingService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->creditNoteRepository->search($filters, $perPage);
    }

    public function create(array $data): CreditNote
    {
        return DB::transaction(function () use ($data) {
            $invoice = $this->invoiceRepository->findOrFail($data['invoice_id']);
            $lines = $data['items'] ?? [];

            [$subtotal, $totalAmount] = $this->validateAgainstInvoice($invoice, $lines, $data);

            $creditNote = $this->creditNoteRepository->create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'credit_note_date' => $data['credit_note_date'],
                'reason' => $data['reason'],
                'subtotal' => $subtotal,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'tax_amount' => $data['tax_amount'] ?? 0,
                'total_amount' => $totalAmount,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->replaceLines($creditNote, $lines);

            $creditNote = $creditNote->fresh(self::EAGER);
            $this->auditLogService->record('created', 'credit_note', "Created Credit Note \"{$creditNote->document_number}\".");

            return $creditNote;
        });
    }

    public function update(CreditNote $creditNote, array $data): CreditNote
    {
        return DB::transaction(function () use ($creditNote, $data) {
            $this->assertDraft($creditNote, 'updated');

            $invoice = $creditNote->invoice;
            $lines = $data['items'] ?? $creditNote->items->map(fn ($line) => [
                'invoice_item_id' => $line->invoice_item_id,
                'qty_credited' => $line->qty_credited,
                'amount' => (float) $line->amount,
                'restock' => $line->restock,
            ])->all();

            $mergedData = [
                'reason' => $data['reason'] ?? $creditNote->reason->value,
                'discount_amount' => $data['discount_amount'] ?? $creditNote->discount_amount,
                'tax_amount' => $data['tax_amount'] ?? $creditNote->tax_amount,
            ];

            [$subtotal, $totalAmount] = $this->validateAgainstInvoice($invoice, $lines, $mergedData);

            $this->creditNoteRepository->update($creditNote, [
                'credit_note_date' => $data['credit_note_date'] ?? $creditNote->credit_note_date,
                'reason' => $mergedData['reason'],
                'subtotal' => $subtotal,
                'discount_amount' => $mergedData['discount_amount'],
                'tax_amount' => $mergedData['tax_amount'],
                'total_amount' => $totalAmount,
                'remarks' => $data['remarks'] ?? $creditNote->remarks,
            ]);

            if (isset($data['items'])) {
                $creditNote->items()->delete();
                $this->replaceLines($creditNote, $lines);
            }

            $creditNote = $creditNote->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'credit_note', "Updated Credit Note \"{$creditNote->document_number}\".");

            return $creditNote;
        });
    }

    public function delete(CreditNote $creditNote): void
    {
        DB::transaction(function () use ($creditNote) {
            $this->assertDraft($creditNote, 'deleted');
            $documentNumber = $creditNote->document_number;
            $this->creditNoteRepository->delete($creditNote);
            $this->auditLogService->record('deleted', 'credit_note', "Deleted Credit Note \"{$documentNumber}\".");
        });
    }

    /**
     * Locks the Invoice's AccountsReceivable row before re-validating —
     * two concurrent Credit Notes against the same Invoice cannot both
     * pass validation against a stale credited_amount and jointly
     * over-credit it. Same convention as PaymentAllocationService::
     * allocateBatch(). No approval step and no inventory movement this
     * sprint — see class docblock.
     */
    public function submit(CreditNote $creditNote): CreditNote
    {
        return DB::transaction(function () use ($creditNote) {
            $this->assertDraft($creditNote, 'submitted');

            $accountsReceivable = $this->accountsReceivableRepository
                ->lockManyForUpdate([$creditNote->invoice->accountsReceivable->id])
                ->firstOrFail();

            $this->accountsReceivableService->assertWithinCreditableBalance($accountsReceivable, (float) $creditNote->total_amount);

            $creditNote->submit();

            $this->accountsReceivableService->writeDown($accountsReceivable, (float) $creditNote->total_amount);

            $this->accountingService->postForDocument(
                $creditNote,
                $creditNote->journalLines(),
                "Credit Note {$creditNote->document_number} for Invoice {$creditNote->invoice->document_number}",
                $creditNote->credit_note_date->toDateString(),
            );

            $creditNote = $creditNote->fresh(self::EAGER);
            $this->auditLogService->record('submitted', 'credit_note', "Submitted Credit Note \"{$creditNote->document_number}\".");

            return $creditNote;
        });
    }

    /**
     * Undoes a submitted Credit Note — restores the Invoice's creditable
     * balance and reverses the posted journal via the Accounting Engine's
     * existing reverseForDocument(), unchanged. The original journal entry
     * is never mutated, only linked to its reversal. Same pattern as
     * PaymentAllocationService::reverse().
     */
    public function reverse(CreditNote $creditNote): CreditNote
    {
        return DB::transaction(function () use ($creditNote) {
            if ($creditNote->status !== DocumentStatus::SUBMITTED || $creditNote->is_reversed) {
                throw new BusinessException('Only a submitted, not-yet-reversed Credit Note can be reversed.');
            }

            $accountsReceivable = $this->accountsReceivableRepository
                ->lockManyForUpdate([$creditNote->invoice->accountsReceivable->id])
                ->firstOrFail();

            $this->accountsReceivableService->restoreWriteDown($accountsReceivable, (float) $creditNote->total_amount);
            $this->accountingService->reverseForDocument($creditNote);

            $this->creditNoteRepository->update($creditNote, ['is_reversed' => true, 'reversed_at' => now()]);

            $creditNote = $creditNote->fresh(self::EAGER);
            $this->auditLogService->record('reversed', 'credit_note', "Reversed Credit Note \"{$creditNote->document_number}\".");

            return $creditNote;
        });
    }

    /**
     * @param  array<int, array{invoice_item_id: string, qty_credited?: int, amount: float, restock?: bool}>  $lines
     * @return array{0: float, 1: float} [subtotal, total_amount]
     */
    protected function validateAgainstInvoice(Invoice $invoice, array $lines, array $data): array
    {
        if ($invoice->status !== DocumentStatus::SUBMITTED) {
            throw new BusinessException('Credit Notes can only be raised against a submitted Invoice.');
        }

        $accountsReceivable = $invoice->accountsReceivable;
        if ($accountsReceivable === null) {
            throw new BusinessException('This Invoice has no Accounts Receivable to credit.');
        }

        $this->assertNoDuplicateReferences($lines);

        $subtotal = 0.0;
        foreach ($lines as $line) {
            $invoiceItem = $this->invoiceItemRepository->findOrFail($line['invoice_item_id']);

            if ($invoiceItem->invoice_id !== $invoice->id) {
                throw new BusinessException('One or more lines do not belong to the selected Invoice.');
            }

            $qtyCredited = (int) ($line['qty_credited'] ?? 0);
            $amount = (float) $line['amount'];

            if ($qtyCredited < 0 || $amount < 0) {
                throw new BusinessException('Credited quantity and amount cannot be negative.');
            }

            if (($line['restock'] ?? false) && $qtyCredited === 0) {
                throw new BusinessException('A line cannot be marked for restock without a credited quantity.');
            }

            $priorTotals = $this->creditNoteItemRepository->creditedTotalsForInvoiceItem($invoiceItem->id);

            $remainingQty = (int) $invoiceItem->qty - $priorTotals['qty'];
            if ($qtyCredited > $remainingQty) {
                throw new BusinessException("Credited quantity ({$qtyCredited}) exceeds what remains creditable ({$remainingQty}) for {$invoiceItem->item_name}.");
            }

            $remainingAmount = (float) $invoiceItem->amount - $priorTotals['amount'];
            if ($amount > $remainingAmount) {
                throw new BusinessException("Credited amount ({$amount}) exceeds what remains creditable ({$remainingAmount}) for {$invoiceItem->item_name}.");
            }

            $subtotal += $amount;
        }

        $discountAmount = (float) ($data['discount_amount'] ?? 0);
        $taxAmount = (float) ($data['tax_amount'] ?? 0);
        $totalAmount = $subtotal - $discountAmount + $taxAmount;

        if ($totalAmount <= 0) {
            throw new BusinessException('Credit Note total must be greater than zero.');
        }

        $priorCredited = $this->creditNoteRepository->creditedTotalForInvoice($invoice->id);
        $remainingBalance = (float) $invoice->grand_total - $priorCredited;

        if ($totalAmount > $remainingBalance) {
            throw new BusinessException("Credit Note total ({$totalAmount}) exceeds the Invoice's remaining creditable balance ({$remainingBalance}).");
        }

        if (($data['reason'] ?? null) === CreditNoteReason::FULL_CREDIT->value && round($totalAmount, 2) !== round($remainingBalance, 2)) {
            throw new BusinessException("A Full Credit must cover the entire remaining balance ({$remainingBalance}), use Partial Credit otherwise.");
        }

        return [$subtotal, $totalAmount];
    }

    protected function replaceLines(CreditNote $creditNote, array $lines): void
    {
        foreach ($lines as $line) {
            $invoiceItem = $this->invoiceItemRepository->findOrFail($line['invoice_item_id']);

            $this->creditNoteItemRepository->create([
                'credit_note_id' => $creditNote->id,
                'invoice_item_id' => $invoiceItem->id,
                'item_id' => $invoiceItem->item_id,
                'item_code' => $invoiceItem->item_code,
                'item_name' => $invoiceItem->item_name,
                'uom' => $invoiceItem->uom,
                'qty_credited' => $line['qty_credited'] ?? 0,
                'rate' => $invoiceItem->rate,
                'amount' => $line['amount'],
                'restock' => $line['restock'] ?? false,
            ]);
        }
    }

    protected function assertNoDuplicateReferences(array $lines): void
    {
        $ids = array_column($lines, 'invoice_item_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new BusinessException('The same Invoice line cannot appear more than once in a single Credit Note.');
        }
    }

    protected function assertDraft(CreditNote $creditNote, string $action): void
    {
        if ($creditNote->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Credit Notes can be {$action}.");
        }
    }
}
