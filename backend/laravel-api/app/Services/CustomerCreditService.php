<?php

namespace App\Services;

use App\Repositories\CustomerRepository;

/**
 * Sales Order credit/overdue block — the one place "can this customer get a
 * new order" is decided, reused by both SalesOrderService (create/submit,
 * server-side enforcement) and CustomerController::creditStatus (the
 * customer-select-time UX check). Reads AccountsReceivableService only
 * through its public methods — AR is a frozen module, this never touches
 * its internals directly.
 */
class CustomerCreditService
{
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected AccountsReceivableService $accountsReceivableService,
    ) {}

    /**
     * $additionalAmount is the new/in-progress order's own value, added on
     * top of the customer's existing outstanding AR before comparing against
     * the limit — 0 for the customer-select-time check (nothing knowable yet
     * beyond overdue/already-over-limit), the order's real total for
     * create()/submit()'s server-side enforcement.
     */
    public function evaluate(string $customerId, float $additionalAmount = 0): array
    {
        $customer = $this->customerRepository->findOrFail($customerId);
        $overdueInvoices = $this->accountsReceivableService->overdueInvoicesFor($customerId);
        $outstandingTotal = $this->accountsReceivableService->outstandingTotal(['customer_id' => $customerId]);

        $creditLimit = $customer->credit_limit !== null ? (float) $customer->credit_limit : null;
        $isOverdue = $overdueInvoices->isNotEmpty();
        $isOverLimit = $creditLimit !== null && ($outstandingTotal + $additionalAmount) > $creditLimit;

        $messages = [];

        if ($isOverdue) {
            $first = $overdueInvoices->first();
            $overdueAmount = (float) $first->amount - (float) $first->paid_amount;
            $messages[] = "Customer ini overdue Rp {$this->rupiah($overdueAmount)} pada invoice {$first->reference_number} (jatuh tempo {$first->due_date->format('d M Y')}).";
        }

        if ($isOverLimit) {
            $messages[] = "Limit kredit Rp {$this->rupiah($creditLimit)} sudah terpakai Rp {$this->rupiah($outstandingTotal)}, order ini akan melebihi limit.";
        }

        return [
            'is_overdue' => $isOverdue,
            'is_over_limit' => $isOverLimit,
            'is_blocked' => $isOverdue || $isOverLimit,
            'overdue_invoices' => $overdueInvoices->map(fn ($ar) => [
                'id' => $ar->id,
                'reference_number' => $ar->reference_number,
                'due_date' => $ar->due_date->toDateString(),
                'outstanding_amount' => (float) $ar->amount - (float) $ar->paid_amount,
            ])->values()->all(),
            'outstanding_total' => $outstandingTotal,
            'credit_limit' => $creditLimit,
            'available_credit' => $creditLimit !== null ? max(0, $creditLimit - $outstandingTotal) : null,
            'message' => implode(' ', $messages),
        ];
    }

    protected function rupiah(float $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }
}
