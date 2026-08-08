<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Exceptions\BusinessException;
use App\Models\AccountsReceivable;
use App\Models\Invoice;
use App\Models\InvoiceChangeRequest;
use App\Repositories\AccountsReceivableRepository;
use App\Repositories\InvoiceChangeRequestRepository;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\InvoiceRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Approval-gated, one-time nominal unlock for a Submitted Transportation
 * Invoice. Deliberately not built on ApprovalService: that service's
 * requestApproval() is a pre-submit "can this document be submitted" gate
 * (aborts unless status === DRAFT), a different concept from this — a
 * post-submit temporary edit window. Mirrors ApprovalService's dual-write
 * audit pattern (DocumentTimelineService + AuditLogService) instead of
 * reusing its class.
 */
class InvoiceChangeRequestService
{
    protected const INVOICE_EAGER = ['customer', 'salesOrder', 'delivery', 'items', 'tax', 'termsOfPayment', 'accountsReceivable.receiptEntryItems.receiptEntry.cashAccount', 'creditNotes', 'debitNotes'];

    public function __construct(
        protected InvoiceChangeRequestRepository $invoiceChangeRequestRepository,
        protected InvoiceRepository $invoiceRepository,
        protected InvoiceItemRepository $invoiceItemRepository,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountingService $accountingService,
        protected DocumentTimelineService $documentTimelineService,
        protected AuditLogService $auditLogService,
    ) {}

    public function historyFor(string $invoiceId)
    {
        return $this->invoiceChangeRequestRepository->historyFor($invoiceId);
    }

    public function create(array $data): InvoiceChangeRequest
    {
        $invoice = $this->invoiceRepository->findOrFail($data['invoice_id']);

        if ($invoice->invoice_type !== InvoiceType::TRANSPORTATION) {
            throw new BusinessException('Only Transportation Invoices support a nominal change request.');
        }

        if ($invoice->status !== DocumentStatus::SUBMITTED) {
            throw new BusinessException('Only a Submitted Invoice can request a nominal change.');
        }

        $this->assertNominalEditable($invoice);

        if ($this->invoiceChangeRequestRepository->activeFor($invoice->id) !== null) {
            throw new BusinessException('A change request is already active for this Invoice.');
        }

        $changeRequest = DB::transaction(function () use ($invoice, $data) {
            $changeRequest = $this->invoiceChangeRequestRepository->create([
                'invoice_id' => $invoice->id,
                'status' => ApprovalStatus::PENDING,
                'requested_by_id' => Auth::id(),
                'request_reason' => $data['request_reason'],
            ]);

            $this->documentTimelineService->record($invoice, 'nominal_change_requested', null, ['request_reason' => $data['request_reason']]);

            return $changeRequest;
        });

        $this->auditLogService->record('nominal_change_requested', 'invoice', "Requested nominal change for Invoice \"{$invoice->document_number}\".", ['invoice_change_request_id' => $changeRequest->id]);

        return $changeRequest;
    }

    public function approve(InvoiceChangeRequest $changeRequest, ?string $remarks = null): InvoiceChangeRequest
    {
        return $this->decide($changeRequest, ApprovalStatus::APPROVED, 'nominal_change_approved', $remarks);
    }

    public function reject(InvoiceChangeRequest $changeRequest, string $remarks): InvoiceChangeRequest
    {
        return $this->decide($changeRequest, ApprovalStatus::REJECTED, 'nominal_change_rejected', $remarks);
    }

    protected function decide(InvoiceChangeRequest $changeRequest, ApprovalStatus $decision, string $auditAction, ?string $remarks): InvoiceChangeRequest
    {
        if ($changeRequest->status !== ApprovalStatus::PENDING) {
            throw new BusinessException('This change request has already been decided.');
        }

        $invoice = $changeRequest->invoice;

        DB::transaction(function () use ($changeRequest, $decision, $remarks, $auditAction, $invoice) {
            $changeRequest->update([
                'status' => $decision,
                'decided_by_id' => Auth::id(),
                'decision_remarks' => $remarks,
                'decided_at' => now(),
            ]);

            $this->documentTimelineService->record($invoice, $auditAction);
        });

        $this->auditLogService->record(
            $auditAction,
            'invoice',
            ($decision === ApprovalStatus::APPROVED ? 'Approved' : 'Rejected')." nominal change for Invoice \"{$invoice->document_number}\"".($remarks ? ": {$remarks}" : '').'.',
            ['invoice_change_request_id' => $changeRequest->id],
        );

        return $changeRequest->fresh();
    }

    /**
     * The one-time nominal edit. Only Rate is user-editable; qty stays
     * fixed (Delivery-sourced) and amount is recomputed as rate*qty.
     * discount_amount/tax_amount stay frozen, so delta(grand_total) ===
     * delta(subtotal) exactly — the delta JE is always a single AR<->Revenue
     * pair, never a tax/discount line.
     */
    public function applyNominal(InvoiceChangeRequest $changeRequest, array $items): Invoice
    {
        return DB::transaction(function () use ($changeRequest, $items) {
            // Re-fetch and lock the change request itself — closes the double-apply
            // race (two tabs/double-click both trying to consume the same approval).
            $changeRequest = InvoiceChangeRequest::query()->whereKey($changeRequest->id)->lockForUpdate()->firstOrFail();

            if ($changeRequest->status !== ApprovalStatus::APPROVED || $changeRequest->consumed_at !== null) {
                throw new BusinessException('This change request is not currently approved and unconsumed.');
            }

            $invoice = $this->invoiceRepository->findOrFail($changeRequest->invoice_id);

            // Re-check the AR guard — approval and the actual edit can be arbitrarily
            // far apart in time; a payment/Credit/Debit Note may have landed since.
            $this->assertNominalEditable($invoice);

            $accountsReceivable = $invoice->accountsReceivable;
            $this->accountsReceivableRepository->lockManyForUpdate([$accountsReceivable->id]);

            $rateById = collect($items)->pluck('rate', 'id');
            $oldRateById = $invoice->items->pluck('rate', 'id');
            $itemChanges = [];

            foreach ($invoice->items as $item) {
                if (! $rateById->has($item->id)) {
                    continue;
                }

                $newRate = (float) $rateById->get($item->id);
                $this->invoiceItemRepository->update($item, ['rate' => $newRate, 'amount' => $newRate * $item->qty]);
                $itemChanges[] = ['id' => $item->id, 'old_rate' => (float) $oldRateById->get($item->id), 'new_rate' => $newRate];
            }

            $newSubtotal = (float) $invoice->items()->sum('amount');
            $newGrandTotal = $newSubtotal - (float) $invoice->discount_amount + (float) $invoice->tax_amount;

            if ($newGrandTotal < 0) {
                throw new BusinessException('Grand total cannot be negative.');
            }

            $oldGrandTotal = (float) $invoice->grand_total;
            $delta = $newGrandTotal - $oldGrandTotal;

            if ($delta === 0.0) {
                throw new BusinessException('No nominal change was submitted.');
            }

            $this->invoiceRepository->update($invoice, ['subtotal' => $newSubtotal, 'grand_total' => $newGrandTotal]);

            $increaseType = $delta > 0 ? 'debit' : 'credit';
            $decreaseType = $delta > 0 ? 'credit' : 'debit';
            $lines = [
                ['account' => '1200', 'type' => $increaseType, 'amount' => abs($delta)],
                ['account' => '4000', 'type' => $decreaseType, 'amount' => abs($delta)],
            ];
            $this->accountingService->postForDocument($invoice, $lines, "Koreksi nominal Invoice {$invoice->document_number}", now()->toDateString());

            $this->accountsReceivableService->adjustForNominalChange($accountsReceivable, $delta);

            $changeRequest->update(['consumed_at' => now()]);

            $properties = ['old_grand_total' => $oldGrandTotal, 'new_grand_total' => $newGrandTotal, 'items' => $itemChanges];
            $this->documentTimelineService->record($invoice, 'nominal_changed', null, $properties);
            $this->auditLogService->record(
                'nominal_changed',
                'invoice',
                "Nominal changed for Invoice \"{$invoice->document_number}\": Rp{$oldGrandTotal} -> Rp{$newGrandTotal}.",
                $properties + ['invoice_change_request_id' => $changeRequest->id],
            );

            return $invoice->fresh(self::INVOICE_EAGER);
        });
    }

    protected function assertNominalEditable(Invoice $invoice): AccountsReceivable
    {
        $accountsReceivable = $invoice->accountsReceivable;

        if ($accountsReceivable === null) {
            throw new BusinessException('This Invoice has no Accounts Receivable record.');
        }

        if ((float) $accountsReceivable->paid_amount > 0 || (float) $accountsReceivable->credited_amount > 0 || (float) $accountsReceivable->debited_amount > 0) {
            throw new BusinessException('Cannot change nominal on an Invoice that already has a payment, Credit Note, or Debit Note applied.');
        }

        return $accountsReceivable;
    }
}
