<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountsReceivable;
use App\Models\PaymentAllocation;
use App\Models\ReceiptEntry;
use App\Repositories\AccountsReceivableRepository;
use App\Repositories\PaymentAllocationRepository;
use App\Repositories\ReceiptEntryRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies an already-received Payment (ReceiptEntry) to one or more
 * outstanding Invoices' receivables. Receiving money and applying it are
 * separate operations — see ReceiptEntry::submit() and
 * docs/PAYMENT_ALLOCATION_DESIGN.md. This is the only place a
 * PaymentAllocation row is ever created or reversed.
 */
class PaymentAllocationService
{
    public function __construct(
        protected PaymentAllocationRepository $paymentAllocationRepository,
        protected ReceiptEntryRepository $receiptEntryRepository,
        protected AccountsReceivableRepository $accountsReceivableRepository,
        protected AccountsReceivableService $accountsReceivableService,
        protected AccountingService $accountingService,
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<int, array{accounts_receivable_id: string, amount: float}>  $lines
     */
    public function allocateBatch(ReceiptEntry $payment, array $lines): Collection
    {
        return DB::transaction(function () use ($payment, $lines) {
            if (count($lines) < 1) {
                throw new BusinessException('At least one allocation line is required.');
            }

            $this->assertNoDuplicateReferences($lines);

            // Lock the payment and every targeted receivable up front, AR
            // rows in a fixed order, so two concurrent batches can never
            // deadlock against each other — see AccountsReceivableRepository::
            // lockManyForUpdate().
            $payment = $this->receiptEntryRepository->lockForUpdate($payment->id);
            $accountsReceivables = $this->accountsReceivableRepository->lockManyForUpdate(
                array_column($lines, 'accounts_receivable_id')
            );

            if ($payment->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException('Only a submitted payment can be allocated.');
            }

            $remaining = $payment->unallocatedAmount();
            $totalAllocated = 0.0;
            $allocations = new Collection();

            foreach ($lines as $line) {
                $amount = (float) $line['amount'];
                $accountsReceivable = $accountsReceivables->firstWhere('id', $line['accounts_receivable_id']);

                if ($accountsReceivable === null) {
                    throw new BusinessException("Accounts Receivable {$line['accounts_receivable_id']} was not found.");
                }

                if ($accountsReceivable->customer_id !== $payment->customer_id) {
                    throw new BusinessException('Accounts Receivable does not belong to the payment\'s customer.');
                }

                $this->assertOriginatesFromInvoice($accountsReceivable);

                if ($amount > $remaining) {
                    throw new BusinessException("Amount ({$amount}) exceeds the payment's unallocated balance ({$remaining}).");
                }

                $this->accountsReceivableService->assertWithinOutstanding($accountsReceivable, $amount);

                $allocation = $this->paymentAllocationRepository->create([
                    'receipt_entry_id' => $payment->id,
                    'accounts_receivable_id' => $accountsReceivable->id,
                    'allocated_amount' => $amount,
                    'allocation_date' => now()->toDateString(),
                    'is_reversed' => false,
                ]);

                $this->accountsReceivableService->settle($accountsReceivable, $amount);

                $this->accountingService->postForDocument(
                    $allocation,
                    $allocation->journalLines(),
                    "Allocation of {$payment->document_number} to {$accountsReceivable->reference_number}",
                    $allocation->allocation_date->toDateString(),
                );

                $remaining -= $amount;
                $totalAllocated += $amount;
                $allocations->push($allocation->fresh(['accountsReceivable']));
            }

            $this->receiptEntryRepository->update($payment, [
                'allocated_amount' => (float) $payment->allocated_amount + $totalAllocated,
            ]);

            $this->auditLogService->record('allocated', 'payment_allocation', "Allocated {$payment->document_number} across ".count($lines).' receivable(s).');

            return $allocations;
        });
    }

    public function reverse(PaymentAllocation $allocation): PaymentAllocation
    {
        return DB::transaction(function () use ($allocation) {
            if ($allocation->is_reversed) {
                throw new BusinessException('This allocation has already been reversed.');
            }

            $payment = $this->receiptEntryRepository->lockForUpdate($allocation->receipt_entry_id);
            $accountsReceivable = $this->accountsReceivableRepository
                ->lockManyForUpdate([$allocation->accounts_receivable_id])
                ->firstOrFail();

            $this->accountsReceivableService->unsettle($accountsReceivable, (float) $allocation->allocated_amount);
            $this->accountingService->reverseForDocument($allocation);

            $this->paymentAllocationRepository->update($allocation, ['is_reversed' => true]);

            $this->receiptEntryRepository->update($payment, [
                'allocated_amount' => (float) $payment->allocated_amount - (float) $allocation->allocated_amount,
            ]);

            $allocation = $allocation->fresh(['accountsReceivable', 'receiptEntry']);
            $this->auditLogService->record('reversed', 'payment_allocation', "Reversed allocation of {$allocation->receiptEntry->document_number}.");

            return $allocation;
        });
    }

    protected function assertNoDuplicateReferences(array $lines): void
    {
        $ids = array_column($lines, 'accounts_receivable_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new BusinessException('The same Accounts Receivable cannot appear more than once in a single allocation batch.');
        }
    }

    /**
     * CR-001: payment must always originate from an Invoice under the
     * finalized workflow (Delivery -> Invoice -> AR -> Payment). Moved here
     * from ReceiptEntryService when allocation became a separate operation
     * (Sprint 12) — this is the new place a payment gets linked to a
     * specific receivable, so this is where the guard belongs. Only blocks
     * *new* allocations — existing Receipt Entries already referencing a
     * legacy Delivery-only receivable (pre-dating the Invoice module) are
     * left alone.
     */
    protected function assertOriginatesFromInvoice(AccountsReceivable $accountsReceivable): void
    {
        if ($accountsReceivable->invoice_id === null) {
            throw new BusinessException('Payment can only be allocated against an Invoice. This receivable has no linked Invoice.');
        }
    }
}
