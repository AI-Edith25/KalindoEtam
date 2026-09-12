<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PaymentEntryType;
use App\Exceptions\BusinessException;
use App\Models\PaymentEntry;
use App\Repositories\AccountsPayableRepository;
use App\Repositories\PaymentEntryAllocationRepository;
use App\Repositories\PaymentEntryExpenseLineRepository;
use App\Repositories\PaymentEntryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Paying a supplier (or posting a General Expense) only — records that
 * money went out and posts its own Dr Advance to Suppliers (or Expense
 * account) / Cr Cash/Bank journal. Applying that money to specific
 * supplier bills is a separate operation; see
 * PaymentEntryAllocationService::allocateBatch(). Mirrors
 * ReceiptEntryService exactly.
 */
class PaymentEntryService
{
    public function __construct(
        protected PaymentEntryRepository $paymentEntryRepository,
        protected PaymentEntryAllocationRepository $paymentEntryAllocationRepository,
        protected PaymentEntryExpenseLineRepository $paymentEntryExpenseLineRepository,
        protected AccountsPayableRepository $accountsPayableRepository,
        protected AccountsPayableService $accountsPayableService,
        protected AccountingService $accountingService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->paymentEntryRepository->search($filters, $perPage);
    }

    public function create(array $data): PaymentEntry
    {
        return DB::transaction(function () use ($data) {
            $paymentType = PaymentEntryType::from($data['payment_type'] ?? PaymentEntryType::SUPPLIER->value);

            if ($paymentType === PaymentEntryType::MIXED) {
                // Header only — no lines yet. A mixed voucher's lines are never persisted
                // before submit (see PaymentEntryService::submit()'s MIXED branch and
                // PaymentEntryExpenseLine's own doc comment for why): the user builds them in
                // the frontend, then posts the whole array to submit() in one atomic call.
                $paymentEntry = $this->paymentEntryRepository->create([
                    'payment_type' => $paymentType,
                    'payment_date' => $data['payment_date'],
                    'cash_account_id' => $data['cash_account_id'],
                    'branch_id' => $data['branch_id'] ?? null,
                    'reference_number' => $data['reference_number'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'total_amount' => $data['amount'],
                    'allocated_amount' => 0,
                ]);

                $paymentEntry = $paymentEntry->fresh(['cashAccount']);
                $this->auditLogService->record('created', 'payment_entry', "Created Payment Entry \"{$paymentEntry->document_number}\".");

                return $paymentEntry;
            }

            if ($paymentType === PaymentEntryType::GENERAL_EXPENSE) {
                $paymentEntry = $this->paymentEntryRepository->create([
                    'payment_type' => $paymentType,
                    'expense_account_id' => $data['expense_account_id'],
                    'description' => $data['description'],
                    'payment_date' => $data['payment_date'],
                    'cash_account_id' => $data['cash_account_id'],
                    'branch_id' => $data['branch_id'] ?? null,
                    'reference_number' => $data['reference_number'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'total_amount' => $data['amount'],
                ]);

                $paymentEntry = $paymentEntry->fresh(['expenseAccount']);
                $this->auditLogService->record('created', 'payment_entry', "Created Payment Entry \"{$paymentEntry->document_number}\".");

                return $paymentEntry;
            }

            $paymentEntry = $this->paymentEntryRepository->create([
                'payment_type' => $paymentType,
                'supplier_id' => $data['supplier_id'],
                'payment_date' => $data['payment_date'],
                'cash_account_id' => $data['cash_account_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'total_amount' => $data['amount'],
                'allocated_amount' => 0,
            ]);

            $paymentEntry = $paymentEntry->fresh(['supplier']);
            $this->auditLogService->record('created', 'payment_entry', "Created Payment Entry \"{$paymentEntry->document_number}\".");

            return $paymentEntry;
        });
    }

    public function update(PaymentEntry $paymentEntry, array $data): PaymentEntry
    {
        return DB::transaction(function () use ($paymentEntry, $data) {
            $this->assertDraft($paymentEntry, 'updated');

            if ($paymentEntry->payment_type === PaymentEntryType::MIXED) {
                $headerData = collect($data)->except('amount')->all();

                if (isset($data['amount'])) {
                    $headerData['total_amount'] = $data['amount'];
                }

                $this->paymentEntryRepository->update($paymentEntry, $headerData);

                $paymentEntry = $paymentEntry->fresh(['cashAccount']);
                $this->auditLogService->record('updated', 'payment_entry', "Updated Payment Entry \"{$paymentEntry->document_number}\".");

                return $paymentEntry;
            }

            if ($paymentEntry->payment_type === PaymentEntryType::GENERAL_EXPENSE) {
                $headerData = collect($data)->except('amount')->all();

                if (isset($data['amount'])) {
                    $headerData['total_amount'] = $data['amount'];
                }

                $this->paymentEntryRepository->update($paymentEntry, $headerData);

                $paymentEntry = $paymentEntry->fresh(['expenseAccount']);
                $this->auditLogService->record('updated', 'payment_entry', "Updated Payment Entry \"{$paymentEntry->document_number}\".");

                return $paymentEntry;
            }

            $headerData = collect($data)->except('amount')->all();

            if (isset($data['amount'])) {
                $headerData['total_amount'] = $data['amount'];
            }

            $this->paymentEntryRepository->update($paymentEntry, $headerData);

            $paymentEntry = $paymentEntry->fresh(['supplier']);
            $this->auditLogService->record('updated', 'payment_entry', "Updated Payment Entry \"{$paymentEntry->document_number}\".");

            return $paymentEntry;
        });
    }

    public function delete(PaymentEntry $paymentEntry): void
    {
        DB::transaction(function () use ($paymentEntry) {
            $this->assertDraft($paymentEntry, 'deleted');
            $documentNumber = $paymentEntry->document_number;
            $this->paymentEntryRepository->delete($paymentEntry);
            $this->auditLogService->record('deleted', 'payment_entry', "Deleted Payment Entry \"{$documentNumber}\".");
        });
    }

    /**
     * Posts the full total_amount, regardless of whether any of it gets
     * allocated to a specific bill in this same request — Advance to
     * Suppliers (1250) for a Supplier payment, or the chosen Expense
     * account directly for a General Expense payment (no payable involved
     * at all, so no suspense leg). Allocating to a specific
     * AccountsPayable is always a separate follow-up call to
     * PaymentEntryAllocationService::allocateBatch() (see
     * PaymentEntryAllocationController::store()), never done here — same
     * split as ReceiptEntryService::submit()/PaymentAllocationService.
     */
    /**
     * @param  array<int, array{type: 'supplier'|'expense', accounts_payable_id?: string, expense_account_id?: string, description?: string, branch_id?: string, amount: float, notes?: string}>  $lines
     *         Only meaningful (and required — see SubmitPaymentEntryRequest) for payment_type=mixed.
     *         Ignored for supplier/general_expense, which keep their existing submit-then-allocate flow.
     */
    public function submit(PaymentEntry $paymentEntry, array $lines = []): PaymentEntry
    {
        return DB::transaction(function () use ($paymentEntry, $lines) {
            if ($paymentEntry->payment_type === PaymentEntryType::MIXED) {
                return $this->submitMixed($paymentEntry, $lines);
            }

            if ($paymentEntry->payment_type === PaymentEntryType::GENERAL_EXPENSE) {
                $paymentEntry->load('expenseAccount');
                $paymentEntry->submit();
                $this->postJournalEntry($paymentEntry);

                $paymentEntry = $paymentEntry->fresh(['expenseAccount']);
                $this->auditLogService->record('submitted', 'payment_entry', "Submitted Payment Entry \"{$paymentEntry->document_number}\".");

                return $paymentEntry;
            }

            $paymentEntry->load('supplier');
            $paymentEntry->submit();
            $this->postJournalEntry($paymentEntry);

            $paymentEntry = $paymentEntry->fresh(['supplier']);
            $this->auditLogService->record('submitted', 'payment_entry', "Submitted Payment Entry \"{$paymentEntry->document_number}\".");

            return $paymentEntry;
        });
    }

    /**
     * Submitting a mixed voucher does, in one atomic transaction, what a plain supplier
     * voucher normally spreads across two separate requests (submit, then a follow-up
     * PaymentEntryAllocationService::allocateBatch() call) — because a mixed voucher's exact
     * lines (which bills, which expense accounts, how much each) are only ever known once,
     * at submit time (see PaymentEntry::create()'s MIXED branch). Deliberately does NOT call
     * allocateBatch() itself: that method hard-checks every AccountsPayable belongs to the
     * payment's own supplier_id, which is null for a mixed voucher whose lines can span
     * several different suppliers. Instead it inlines the same per-line steps
     * (assertWithinOutstanding -> create allocation -> settle -> post that line's own
     * Dr 2000/Cr 1250 journal) directly, using the same row-locking idiom.
     */
    protected function submitMixed(PaymentEntry $paymentEntry, array $lines): PaymentEntry
    {
        if (count($lines) < 1) {
            throw new BusinessException('At least one payment allocation line is required.');
        }

        $supplierLines = array_values(array_filter($lines, fn (array $line) => $line['type'] === 'supplier'));
        $expenseLines = array_values(array_filter($lines, fn (array $line) => $line['type'] === 'expense'));

        $apIds = array_column($supplierLines, 'accounts_payable_id');
        if (count($apIds) !== count(array_unique($apIds))) {
            throw new BusinessException('The same Accounts Payable cannot appear more than once in a single voucher.');
        }

        $totalLines = array_sum(array_column($lines, 'amount'));
        if ($totalLines > (float) $paymentEntry->total_amount) {
            throw new BusinessException("Sum of allocation lines ({$totalLines}) exceeds the voucher's Amount Paid ({$paymentEntry->total_amount}).");
        }

        // Lock the payment and every targeted payable up front, payable rows in a fixed
        // order, so two concurrent submits can never deadlock — same idiom as
        // PaymentEntryAllocationService::allocateBatch().
        $paymentEntry = $this->paymentEntryRepository->lockForUpdate($paymentEntry->id);
        $accountsPayables = count($apIds) > 0
            ? $this->accountsPayableRepository->lockManyForUpdate($apIds)
            : collect();

        foreach ($supplierLines as $line) {
            $amount = (float) $line['amount'];
            $accountsPayable = $accountsPayables->firstWhere('id', $line['accounts_payable_id']);

            if ($accountsPayable === null) {
                throw new BusinessException("Accounts Payable {$line['accounts_payable_id']} was not found.");
            }

            $this->accountsPayableService->assertWithinOutstanding($accountsPayable, $amount);

            $allocation = $this->paymentEntryAllocationRepository->create([
                'payment_entry_id' => $paymentEntry->id,
                'accounts_payable_id' => $accountsPayable->id,
                'allocated_amount' => $amount,
                'allocation_date' => now()->toDateString(),
                'is_reversed' => false,
            ]);

            $this->accountsPayableService->settle($accountsPayable, $amount);

            $this->accountingService->postForDocument(
                $allocation,
                $allocation->journalLines(),
                "Allocation of {$paymentEntry->document_number} to {$accountsPayable->reference_number}",
                $allocation->allocation_date->toDateString(),
            );
        }

        $lineNo = 1;
        foreach ($expenseLines as $line) {
            $this->paymentEntryExpenseLineRepository->create([
                'payment_entry_id' => $paymentEntry->id,
                'line_no' => $lineNo++,
                'expense_account_id' => $line['expense_account_id'],
                'description' => $line['description'],
                'branch_id' => $line['branch_id'] ?? null,
                'amount' => (float) $line['amount'],
                'notes' => $line['notes'] ?? null,
            ]);
        }

        $paymentEntry->load(['cashAccount', 'items.accountsPayable', 'expenseLines.expenseAccount']);
        $paymentEntry->submit();

        $this->paymentEntryRepository->update($paymentEntry, ['allocated_amount' => $totalLines]);

        $this->postJournalEntry($paymentEntry);

        $paymentEntry = $paymentEntry->fresh(['cashAccount', 'items.accountsPayable', 'expenseLines.expenseAccount']);
        $this->auditLogService->record('submitted', 'payment_entry', "Submitted Payment Entry \"{$paymentEntry->document_number}\".");

        return $paymentEntry;
    }

    protected function postJournalEntry(PaymentEntry $paymentEntry): void
    {
        $this->accountingService->postForDocument(
            $paymentEntry,
            $paymentEntry->journalLines(),
            "Payment Entry {$paymentEntry->document_number}",
            $paymentEntry->payment_date->toDateString(),
        );
    }

    protected function assertDraft(PaymentEntry $paymentEntry, string $action): void
    {
        if ($paymentEntry->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Payment Entries can be {$action}.");
        }
    }
}
