<?php

namespace App\Services;

use App\Enums\DocumentStatus;
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
            $receiptEntry = $this->receiptEntryRepository->create([
                'customer_id' => $data['customer_id'],
                'receipt_date' => $data['receipt_date'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'total_amount' => $data['total_amount'],
                'allocated_amount' => 0,
            ]);

            $this->auditLogService->record('created', 'receipt_entry', "Created Receipt Entry \"{$receiptEntry->document_number}\".");

            return $receiptEntry;
        });
    }

    public function update(ReceiptEntry $receiptEntry, array $data): ReceiptEntry
    {
        return DB::transaction(function () use ($receiptEntry, $data) {
            $this->assertDraft($receiptEntry, 'updated');

            $this->receiptEntryRepository->update($receiptEntry, $data);

            $receiptEntry = $receiptEntry->fresh(['customer']);
            $this->auditLogService->record('updated', 'receipt_entry', "Updated Receipt Entry \"{$receiptEntry->document_number}\".");

            return $receiptEntry;
        });
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

            $receiptEntry = $receiptEntry->fresh(['customer']);
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
