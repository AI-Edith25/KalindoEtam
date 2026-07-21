<?php

namespace App\Services;

use App\Enums\DebitNoteReason;
use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Repositories\AccountsReceivableRepository;
use App\Repositories\DebitNoteItemRepository;
use App\Repositories\DebitNoteRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\InvoiceRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Increases a customer's receivable after a submitted Invoice — the
 * counterpart to CreditNoteService. No approval gate this sprint: the
 * Sprint 14A architecture decision defers a dedicated approval workflow
 * until a shared Approval Workflow Engine exists (see
 * docs/DEBIT_NOTE_DESIGN.md §4's "Approval" section, superseded). Lifecycle
 * is Draft -> Submitted only, identical to CreditNote's.
 */
class DebitNoteService
{
    protected const EAGER = ['invoice', 'customer', 'items.invoiceItem', 'items.item'];

    public function __construct(
        protected DebitNoteRepository $debitNoteRepository,
        protected DebitNoteItemRepository $debitNoteItemRepository,
        protected InvoiceRepository $invoiceRepository,
        protected InvoiceItemRepository $invoiceItemRepository,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountingService $accountingService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->debitNoteRepository->search($filters, $perPage);
    }

    public function create(array $data): DebitNote
    {
        return DB::transaction(function () use ($data) {
            $invoice = $this->invoiceRepository->findOrFail($data['invoice_id']);
            $lines = $data['items'] ?? [];

            [$normalizedLines, $subtotalGoods, $subtotalOther, $totalAmount] = $this->validateAgainstInvoice($invoice, $lines, $data);

            $debitNote = $this->debitNoteRepository->create([
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'debit_note_date' => $data['debit_note_date'],
                'reason' => $data['reason'],
                'subtotal_goods' => $subtotalGoods,
                'subtotal_other' => $subtotalOther,
                'tax_amount' => $data['tax_amount'] ?? 0,
                'total_amount' => $totalAmount,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->replaceLines($debitNote, $normalizedLines);

            $debitNote = $debitNote->fresh(self::EAGER);
            $this->auditLogService->record('created', 'debit_note', "Created Debit Note \"{$debitNote->document_number}\".");

            return $debitNote;
        });
    }

    public function update(DebitNote $debitNote, array $data): DebitNote
    {
        return DB::transaction(function () use ($debitNote, $data) {
            $this->assertDraft($debitNote, 'updated');

            $invoice = $debitNote->invoice;
            $lines = $data['items'] ?? $debitNote->items->map(fn ($line) => [
                'invoice_item_id' => $line->invoice_item_id,
                'description' => $line->description,
                'qty_adjusted' => $line->qty_adjusted,
                'rate' => $line->rate,
                'amount' => (float) $line->amount,
            ])->all();

            $mergedData = [
                'reason' => $data['reason'] ?? $debitNote->reason->value,
                'tax_amount' => $data['tax_amount'] ?? $debitNote->tax_amount,
            ];

            [$normalizedLines, $subtotalGoods, $subtotalOther, $totalAmount] = $this->validateAgainstInvoice($invoice, $lines, $mergedData);

            $this->debitNoteRepository->update($debitNote, [
                'debit_note_date' => $data['debit_note_date'] ?? $debitNote->debit_note_date,
                'reason' => $mergedData['reason'],
                'subtotal_goods' => $subtotalGoods,
                'subtotal_other' => $subtotalOther,
                'tax_amount' => $mergedData['tax_amount'],
                'total_amount' => $totalAmount,
                'remarks' => $data['remarks'] ?? $debitNote->remarks,
            ]);

            if (isset($data['items'])) {
                $debitNote->items()->delete();
                $this->replaceLines($debitNote, $normalizedLines);
            }

            $debitNote = $debitNote->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'debit_note', "Updated Debit Note \"{$debitNote->document_number}\".");

            return $debitNote;
        });
    }

    public function delete(DebitNote $debitNote): void
    {
        DB::transaction(function () use ($debitNote) {
            $this->assertDraft($debitNote, 'deleted');
            $documentNumber = $debitNote->document_number;
            $this->debitNoteRepository->delete($debitNote);
            $this->auditLogService->record('deleted', 'debit_note', "Deleted Debit Note \"{$documentNumber}\".");
        });
    }

    /**
     * Locks the Invoice's AccountsReceivable row before the write-up — not
     * to guard a ceiling (there isn't one, unlike CreditNoteService::submit()),
     * but to prevent a lost update: two concurrent Debit Notes both reading
     * a stale `amount` would otherwise let the second overwrite the first's
     * increase. See docs/DEBIT_NOTE_DESIGN.md §4/§7.
     */
    public function submit(DebitNote $debitNote): DebitNote
    {
        return DB::transaction(function () use ($debitNote) {
            $this->assertDraft($debitNote, 'submitted');

            $accountsReceivable = $this->accountsReceivableRepository
                ->lockManyForUpdate([$debitNote->invoice->accountsReceivable->id])
                ->firstOrFail();

            $debitNote->submit();

            $this->accountsReceivableService->writeUp($accountsReceivable, (float) $debitNote->total_amount);

            $this->accountingService->postForDocument(
                $debitNote,
                $debitNote->journalLines(),
                "Debit Note {$debitNote->document_number} for Invoice {$debitNote->invoice->document_number}",
                $debitNote->debit_note_date->toDateString(),
            );

            $debitNote = $debitNote->fresh(self::EAGER);
            $this->auditLogService->record('submitted', 'debit_note', "Submitted Debit Note \"{$debitNote->document_number}\".");

            return $debitNote;
        });
    }

    /**
     * Undoes a submitted Debit Note — restores the Invoice's prior balance
     * and reverses the posted journal via the Accounting Engine's existing
     * reverseForDocument(), unchanged. Same pattern as CreditNoteService::reverse().
     */
    public function reverse(DebitNote $debitNote): DebitNote
    {
        return DB::transaction(function () use ($debitNote) {
            if ($debitNote->status !== DocumentStatus::SUBMITTED || $debitNote->is_reversed) {
                throw new BusinessException('Only a submitted, not-yet-reversed Debit Note can be reversed.');
            }

            $accountsReceivable = $this->accountsReceivableRepository
                ->lockManyForUpdate([$debitNote->invoice->accountsReceivable->id])
                ->firstOrFail();

            $this->accountsReceivableService->restoreWriteUp($accountsReceivable, (float) $debitNote->total_amount);
            $this->accountingService->reverseForDocument($debitNote);

            $this->debitNoteRepository->update($debitNote, ['is_reversed' => true, 'reversed_at' => now()]);

            $debitNote = $debitNote->fresh(self::EAGER);
            $this->auditLogService->record('reversed', 'debit_note', "Reversed Debit Note \"{$debitNote->document_number}\".");

            return $debitNote;
        });
    }

    /**
     * @param  array<int, array{invoice_item_id?: ?string, description?: ?string, qty_adjusted?: int, rate?: ?float, amount: float}>  $lines
     * @return array{0: array, 1: float, 2: float, 3: float} [normalizedLines, subtotalGoods, subtotalOther, totalAmount]
     */
    protected function validateAgainstInvoice(Invoice $invoice, array $lines, array $data): array
    {
        if ($invoice->status !== DocumentStatus::SUBMITTED) {
            throw new BusinessException('Debit Notes can only be raised against a submitted Invoice.');
        }

        if ($invoice->accountsReceivable === null) {
            throw new BusinessException('This Invoice has no Accounts Receivable to debit.');
        }

        $reason = $data['reason'] ?? null;

        if ($reason === DebitNoteReason::TAX_ADJUSTMENT->value && count($lines) > 0) {
            throw new BusinessException('A Tax Adjustment Debit Note has no lines — set tax_amount only.');
        }

        $this->assertNoDuplicateReferences($lines);

        $normalizedLines = [];
        $subtotalGoods = 0.0;
        $subtotalOther = 0.0;

        foreach ($lines as $line) {
            $invoiceItemId = $line['invoice_item_id'] ?? null;
            $amount = (float) ($line['amount'] ?? 0);

            if ($amount <= 0) {
                throw new BusinessException('Each Debit Note line must have an amount greater than zero.');
            }

            if ($invoiceItemId !== null) {
                $invoiceItem = $this->invoiceItemRepository->findOrFail($invoiceItemId);

                if ($invoiceItem->invoice_id !== $invoice->id) {
                    throw new BusinessException('One or more lines do not belong to the selected Invoice.');
                }

                $qtyAdjusted = (int) ($line['qty_adjusted'] ?? 0);
                if ($qtyAdjusted < 0) {
                    throw new BusinessException('Adjusted quantity cannot be negative.');
                }

                $normalizedLines[] = [
                    'invoice_item_id' => $invoiceItem->id,
                    'item_id' => $invoiceItem->item_id,
                    'item_code' => $invoiceItem->item_code,
                    'item_name' => $invoiceItem->item_name,
                    'uom' => $invoiceItem->uom,
                    'description' => $line['description'] ?? $invoiceItem->item_name,
                    'qty_adjusted' => $qtyAdjusted,
                    'rate' => $line['rate'] ?? $invoiceItem->rate,
                    'amount' => $amount,
                ];

                $subtotalGoods += $amount;
            } else {
                $description = trim((string) ($line['description'] ?? ''));
                if ($description === '') {
                    throw new BusinessException('A freestanding Debit Note line requires a description.');
                }

                $normalizedLines[] = [
                    'invoice_item_id' => null,
                    'item_id' => null,
                    'item_code' => null,
                    'item_name' => null,
                    'uom' => null,
                    'description' => $description,
                    'qty_adjusted' => 0,
                    'rate' => null,
                    'amount' => $amount,
                ];

                $subtotalOther += $amount;
            }
        }

        $taxAmount = (float) ($data['tax_amount'] ?? 0);
        $totalAmount = $subtotalGoods + $subtotalOther + $taxAmount;

        if ($totalAmount <= 0) {
            throw new BusinessException('Debit Note total must be greater than zero.');
        }

        if ($reason === DebitNoteReason::TAX_ADJUSTMENT->value && $taxAmount <= 0) {
            throw new BusinessException('A Tax Adjustment Debit Note requires a tax_amount greater than zero.');
        }

        return [$normalizedLines, $subtotalGoods, $subtotalOther, $totalAmount];
    }

    protected function replaceLines(DebitNote $debitNote, array $normalizedLines): void
    {
        foreach ($normalizedLines as $line) {
            $this->debitNoteItemRepository->create([
                'debit_note_id' => $debitNote->id,
                ...$line,
            ]);
        }
    }

    protected function assertNoDuplicateReferences(array $lines): void
    {
        $ids = array_filter(array_column($lines, 'invoice_item_id'));

        if (count($ids) !== count(array_unique($ids))) {
            throw new BusinessException('The same Invoice line cannot appear more than once in a single Debit Note.');
        }
    }

    protected function assertDraft(DebitNote $debitNote, string $action): void
    {
        if ($debitNote->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Debit Notes can be {$action}.");
        }
    }
}
