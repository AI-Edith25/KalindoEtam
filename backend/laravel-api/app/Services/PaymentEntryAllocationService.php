<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountsPayable;
use App\Models\PaymentEntry;
use App\Models\PaymentEntryAllocation;
use App\Repositories\AccountsPayableRepository;
use App\Repositories\PaymentEntryAllocationRepository;
use App\Repositories\PaymentEntryRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies an already-paid Payment (PaymentEntry) to one or more outstanding
 * supplier bills' payables. Paying money out and applying it are separate
 * operations — see PaymentEntry::submit() and PaymentEntryService. This is
 * the only place a PaymentEntryAllocation row is ever created or reversed.
 * Mirrors PaymentAllocationService (the AR side) field-for-field, with the
 * asset/liability roles swapped — see PaymentEntryAllocation::journalLines().
 */
class PaymentEntryAllocationService
{
    public function __construct(
        protected PaymentEntryAllocationRepository $paymentEntryAllocationRepository,
        protected PaymentEntryRepository $paymentEntryRepository,
        protected AccountsPayableRepository $accountsPayableRepository,
        protected AccountsPayableService $accountsPayableService,
        protected AccountingService $accountingService,
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<int, array{accounts_payable_id: string, amount: float}>  $lines
     */
    public function allocateBatch(PaymentEntry $payment, array $lines): Collection
    {
        return DB::transaction(function () use ($payment, $lines) {
            if (count($lines) < 1) {
                throw new BusinessException('At least one allocation line is required.');
            }

            $this->assertNoDuplicateReferences($lines);

            // Lock the payment and every targeted payable up front, payable rows in a
            // fixed order, so two concurrent batches can never deadlock against each
            // other — see AccountsPayableRepository::lockManyForUpdate().
            $payment = $this->paymentEntryRepository->lockForUpdate($payment->id);
            $accountsPayables = $this->accountsPayableRepository->lockManyForUpdate(
                array_column($lines, 'accounts_payable_id')
            );

            if ($payment->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException('Only a submitted payment can be allocated.');
            }

            $remaining = $payment->unallocatedAmount();
            $totalAllocated = 0.0;
            $allocations = new Collection();

            foreach ($lines as $line) {
                $amount = (float) $line['amount'];
                $accountsPayable = $accountsPayables->firstWhere('id', $line['accounts_payable_id']);

                if ($accountsPayable === null) {
                    throw new BusinessException("Accounts Payable {$line['accounts_payable_id']} was not found.");
                }

                if ($accountsPayable->supplier_id !== $payment->supplier_id) {
                    throw new BusinessException('Accounts Payable does not belong to the payment\'s supplier.');
                }

                if ($amount > $remaining) {
                    throw new BusinessException("Amount ({$amount}) exceeds the payment's unallocated balance ({$remaining}).");
                }

                $this->accountsPayableService->assertWithinOutstanding($accountsPayable, $amount);

                $allocation = $this->paymentEntryAllocationRepository->create([
                    'payment_entry_id' => $payment->id,
                    'accounts_payable_id' => $accountsPayable->id,
                    'allocated_amount' => $amount,
                    'allocation_date' => now()->toDateString(),
                    'is_reversed' => false,
                ]);

                $this->accountsPayableService->settle($accountsPayable, $amount);

                $this->accountingService->postForDocument(
                    $allocation,
                    $allocation->journalLines(),
                    "Allocation of {$payment->document_number} to {$accountsPayable->reference_number}",
                    $allocation->allocation_date->toDateString(),
                );

                $remaining -= $amount;
                $totalAllocated += $amount;
                $allocations->push($allocation->fresh(['accountsPayable']));
            }

            $this->paymentEntryRepository->update($payment, [
                'allocated_amount' => (float) $payment->allocated_amount + $totalAllocated,
            ]);

            $this->auditLogService->record('allocated', 'payment_entry_allocation', "Allocated {$payment->document_number} across ".count($lines).' payable(s).');

            return $allocations;
        });
    }

    public function reverse(PaymentEntryAllocation $allocation): PaymentEntryAllocation
    {
        return DB::transaction(function () use ($allocation) {
            if ($allocation->is_reversed) {
                throw new BusinessException('This allocation has already been reversed.');
            }

            $payment = $this->paymentEntryRepository->lockForUpdate($allocation->payment_entry_id);
            $accountsPayable = $this->accountsPayableRepository
                ->lockManyForUpdate([$allocation->accounts_payable_id])
                ->firstOrFail();

            $this->accountsPayableService->unsettle($accountsPayable, (float) $allocation->allocated_amount);
            $this->accountingService->reverseForDocument($allocation);

            $this->paymentEntryAllocationRepository->update($allocation, ['is_reversed' => true]);

            $this->paymentEntryRepository->update($payment, [
                'allocated_amount' => (float) $payment->allocated_amount - (float) $allocation->allocated_amount,
            ]);

            $allocation = $allocation->fresh(['accountsPayable', 'paymentEntry']);
            $this->auditLogService->record('reversed', 'payment_entry_allocation', "Reversed allocation of {$allocation->paymentEntry->document_number}.");

            return $allocation;
        });
    }

    protected function assertNoDuplicateReferences(array $lines): void
    {
        $ids = array_column($lines, 'accounts_payable_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new BusinessException('The same Accounts Payable cannot appear more than once in a single allocation batch.');
        }
    }
}
