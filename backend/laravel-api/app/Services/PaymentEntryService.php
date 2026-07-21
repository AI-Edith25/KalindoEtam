<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountsPayable;
use App\Models\PaymentEntry;
use App\Repositories\AccountsPayableRepository;
use App\Repositories\PaymentEntryItemRepository;
use App\Repositories\PaymentEntryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentEntryService
{
    public function __construct(
        protected PaymentEntryRepository $paymentEntryRepository,
        protected PaymentEntryItemRepository $paymentEntryItemRepository,
        protected AccountsPayableRepository $accountsPayableRepository,
        protected AccountsPayableService $accountsPayableService,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->paymentEntryRepository->search($filters, $perPage);
    }

    public function create(array $data): PaymentEntry
    {
        return DB::transaction(function () use ($data) {
            $this->assertNoDuplicateReferences($data['items'], 'accounts_payable_id');

            $paymentEntry = $this->paymentEntryRepository->create([
                'supplier_id' => $data['supplier_id'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'total_amount' => $this->sumLines($data['items'], 'paid_amount'),
            ]);

            foreach ($data['items'] as $line) {
                $this->addLine($paymentEntry, $data['supplier_id'], $line['accounts_payable_id'], $line['paid_amount']);
            }

            $paymentEntry = $paymentEntry->fresh(['supplier', 'items.accountsPayable']);
            $this->auditLogService->record('created', 'payment_entry', "Created Payment Entry \"{$paymentEntry->document_number}\".");

            return $paymentEntry;
        });
    }

    public function update(PaymentEntry $paymentEntry, array $data): PaymentEntry
    {
        return DB::transaction(function () use ($paymentEntry, $data) {
            $this->assertDraft($paymentEntry, 'updated');

            $headerData = collect($data)->except('items')->all();

            if (isset($data['items'])) {
                $this->assertNoDuplicateReferences($data['items'], 'accounts_payable_id');
                $paymentEntry->items()->delete();

                foreach ($data['items'] as $line) {
                    $this->addLine($paymentEntry, $data['supplier_id'] ?? $paymentEntry->supplier_id, $line['accounts_payable_id'], $line['paid_amount']);
                }

                $headerData['total_amount'] = $this->sumLines($data['items'], 'paid_amount');
            }

            $this->paymentEntryRepository->update($paymentEntry, $headerData);

            $paymentEntry = $paymentEntry->fresh(['supplier', 'items.accountsPayable']);
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
     * Re-validates every line against the payable's *current* outstanding
     * balance (it may have changed since create()), then settles each one
     * and flips status via Documentable.
     */
    public function submit(PaymentEntry $paymentEntry): PaymentEntry
    {
        return DB::transaction(function () use ($paymentEntry) {
            $paymentEntry->load('items.accountsPayable');

            foreach ($paymentEntry->items as $line) {
                $this->assertWithinOutstanding($line->accountsPayable, (float) $line->paid_amount);
            }

            foreach ($paymentEntry->items as $line) {
                $this->accountsPayableService->settle($line->accountsPayable, (float) $line->paid_amount);
            }

            $paymentEntry->submit();

            $paymentEntry = $paymentEntry->fresh(['supplier', 'items.accountsPayable']);
            $this->auditLogService->record('submitted', 'payment_entry', "Submitted Payment Entry \"{$paymentEntry->document_number}\".");

            return $paymentEntry;
        });
    }

    protected function addLine(PaymentEntry $paymentEntry, string $supplierId, string $accountsPayableId, float $paidAmount): void
    {
        $accountsPayable = $this->accountsPayableRepository->findOrFail($accountsPayableId);

        if ($accountsPayable->supplier_id !== $supplierId) {
            throw new BusinessException('Accounts Payable does not belong to the specified supplier.');
        }

        $this->assertWithinOutstanding($accountsPayable, $paidAmount);

        $this->paymentEntryItemRepository->create([
            'payment_entry_id' => $paymentEntry->id,
            'accounts_payable_id' => $accountsPayable->id,
            'paid_amount' => $paidAmount,
        ]);
    }

    protected function assertWithinOutstanding(AccountsPayable $accountsPayable, float $paidAmount): void
    {
        if ($paidAmount <= 0) {
            throw new BusinessException('paid_amount must be greater than zero.');
        }

        $outstanding = (float) $accountsPayable->amount - (float) $accountsPayable->paid_amount;

        if ($paidAmount > $outstanding) {
            throw new BusinessException("paid_amount ({$paidAmount}) exceeds outstanding payable ({$outstanding}) for {$accountsPayable->reference_number}.");
        }
    }

    protected function assertNoDuplicateReferences(array $items, string $key): void
    {
        $ids = array_column($items, $key);

        if (count($ids) !== count(array_unique($ids))) {
            throw new BusinessException('The same Accounts Payable cannot appear more than once in a single Payment Entry.');
        }
    }

    protected function assertDraft(PaymentEntry $paymentEntry, string $action): void
    {
        if ($paymentEntry->status !== DocumentStatus::DRAFT) {
            throw new BusinessException("Only draft Payment Entries can be {$action}.");
        }
    }

    protected function sumLines(array $items, string $amountKey): float
    {
        return collect($items)->sum(fn (array $line) => $line[$amountKey]);
    }
}
