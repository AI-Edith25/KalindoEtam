<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\ReceiptEntryType;
use App\Exceptions\BusinessException;
use App\Models\ReceiptEntry;
use App\Repositories\ReceiptEntryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Receiving payment only — records that money came in and posts its own
 * Dr Cash/Bank / Cr Unapplied Customer Payments journal. Applying that
 * money to specific invoices is a separate operation; see
 * PaymentAllocationService::allocateBatch().
 */
class ReceiptEntryService
{
    public function __construct(
        protected ReceiptEntryRepository $receiptEntryRepository,
        protected AccountingService $accountingService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->receiptEntryRepository->search($filters, $perPage);
    }

    public function create(array $data): ReceiptEntry
    {
        return DB::transaction(function () use ($data) {
            $paymentType = ReceiptEntryType::from($data['payment_type'] ?? ReceiptEntryType::CUSTOMER->value);

            $receiptEntry = $this->receiptEntryRepository->create([
                'payment_type' => $paymentType,
                'customer_id' => $paymentType === ReceiptEntryType::CUSTOMER ? $data['customer_id'] : null,
                'income_account_id' => $paymentType === ReceiptEntryType::OTHER_INCOME ? $data['income_account_id'] : null,
                'description' => $paymentType === ReceiptEntryType::OTHER_INCOME ? $data['description'] : null,
                'receipt_date' => $data['receipt_date'],
                'cash_account_id' => $data['cash_account_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'total_amount' => $data['total_amount'],
                'allocated_amount' => 0,
                'payment_method' => $data['payment_method'] ?? null,
                'giro_number' => $data['giro_number'] ?? null,
                'giro_due_date' => $data['giro_due_date'] ?? null,
            ]);

            $this->auditLogService->record('created', 'receipt_entry', "Created Receipt Entry \"{$receiptEntry->document_number}\".");

            return $receiptEntry;
        });
    }

    public function update(ReceiptEntry $receiptEntry, array $data): ReceiptEntry
    {
        return DB::transaction(function () use ($receiptEntry, $data) {
            if ($receiptEntry->status === DocumentStatus::CANCELLED) {
                throw new BusinessException('Cancelled Receipt Entries cannot be edited.');
            }

            if ($receiptEntry->status === DocumentStatus::SUBMITTED) {
                $this->updateSubmitted($receiptEntry, $data);
            } else {
                $this->receiptEntryRepository->update($receiptEntry, $data);
            }

            $receiptEntry = $receiptEntry->fresh(['customer', 'branch', 'incomeAccount']);
            $this->auditLogService->record('updated', 'receipt_entry', "Updated Receipt Entry \"{$receiptEntry->document_number}\".");

            return $receiptEntry;
        });
    }

    /**
     * Submitted receipts may still be corrected (wrong cash account, amount,
     * customer) — same "allow it, don't block" policy the GR module uses for
     * confirmed-document edits. Only the journal needs reverse+repost;
     * allocation is a separate, later operation (PaymentAllocationService)
     * so it's left untouched unless the edit would make it inconsistent.
     */
    protected function updateSubmitted(ReceiptEntry $receiptEntry, array $data): void
    {
        $newTotal = (float) ($data['total_amount'] ?? $receiptEntry->total_amount);

        if ($newTotal < (float) $receiptEntry->allocated_amount) {
            throw new BusinessException("Total amount cannot be less than the amount already allocated ({$receiptEntry->allocated_amount}). Reverse the allocation first.");
        }

        if (array_key_exists('customer_id', $data) && $data['customer_id'] !== $receiptEntry->customer_id && (float) $receiptEntry->allocated_amount > 0) {
            throw new BusinessException('Customer cannot be changed once this payment has been allocated to invoices. Reverse the allocation first.');
        }

        $journalAffectingFields = ['total_amount', 'cash_account_id', 'customer_id', 'income_account_id', 'description'];
        $journalChanged = collect($journalAffectingFields)->contains(
            fn (string $field) => array_key_exists($field, $data) && (string) $data[$field] !== (string) $receiptEntry->{$field}
        );

        if ($journalChanged) {
            $this->accountingService->reverseForDocument($receiptEntry);
        }

        $this->receiptEntryRepository->update($receiptEntry, $data);

        if ($journalChanged) {
            $receiptEntry = $receiptEntry->fresh(['customer', 'cashAccount', 'incomeAccount']);
            $this->accountingService->postForDocument($receiptEntry, $receiptEntry->journalLines(), "Receipt {$receiptEntry->document_number}", $receiptEntry->receipt_date->toDateString());
        }
    }

    public function delete(ReceiptEntry $receiptEntry): void
    {
        DB::transaction(function () use ($receiptEntry) {
            $this->assertDraft($receiptEntry, 'deleted');
            $documentNumber = $receiptEntry->document_number;
            $this->receiptEntryRepository->delete($receiptEntry);
            $this->auditLogService->record('deleted', 'receipt_entry', "Deleted Receipt Entry \"{$documentNumber}\".");
        });
    }

    /** Only receives money — flips status and posts the receipt's own journal. Allocation is a separate call; see PaymentAllocationService::allocateBatch(). */
    public function submit(ReceiptEntry $receiptEntry): ReceiptEntry
    {
        return DB::transaction(function () use ($receiptEntry) {
            $receiptEntry->submit();

            $this->accountingService->postForDocument($receiptEntry, $receiptEntry->journalLines(), "Receipt {$receiptEntry->document_number}", $receiptEntry->receipt_date->toDateString());

            $receiptEntry = $receiptEntry->fresh(['customer', 'incomeAccount']);
            $this->auditLogService->record('submitted', 'receipt_entry', "Submitted Receipt Entry \"{$receiptEntry->document_number}\".");

            return $receiptEntry;
        });
    }

    protected function assertDraft(ReceiptEntry $receiptEntry, string $action): void
    {
        if ($receiptEntry->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Receipt Entries can be {$action}.");
        }
    }
}
