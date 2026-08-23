<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\PaymentEntryType;
use App\Exceptions\BusinessException;
use App\Models\PaymentEntry;
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
    public function submit(PaymentEntry $paymentEntry): PaymentEntry
    {
        return DB::transaction(function () use ($paymentEntry) {
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
