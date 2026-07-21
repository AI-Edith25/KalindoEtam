<?php

namespace App\Services;

use App\Enums\AccountsReceivableStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountsReceivable;
use App\Models\Invoice;
use App\Repositories\AccountsReceivableRepository;
use App\Support\SettlementStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AccountsReceivableService
{
    public function __construct(protected AccountsReceivableRepository $accountsReceivableRepository) {}

    public function list(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->accountsReceivableRepository->search($filters, $perPage);
    }

    /**
     * Called only by InvoiceService::submit() — Accounts Receivable is a
     * system-generated side effect, never created directly by a user.
     * Amount is the Invoice's grand_total (authoritative, includes
     * tax/discount), not re-derived from Delivery lines.
     */
    public function createFromInvoice(Invoice $invoice): AccountsReceivable
    {
        return DB::transaction(function () use ($invoice) {
            return $this->accountsReceivableRepository->create([
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'sales_order_id' => $invoice->sales_order_id,
                'delivery_id' => $invoice->delivery_id,
                'reference_number' => $invoice->document_number,
                'amount' => $invoice->grand_total,
                'paid_amount' => 0,
                'due_date' => $invoice->due_date,
                'status' => AccountsReceivableStatus::UNPAID,
            ]);
        });
    }

    /**
     * Called only by ReceiptEntryService::submit() — applies one
     * settlement line against this receivable and recomputes its status.
     */
    public function settle(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable
    {
        return DB::transaction(function () use ($accountsReceivable, $amount) {
            $newPaidAmount = $accountsReceivable->paid_amount + $amount;
            $newStatus = AccountsReceivableStatus::from(
                SettlementStatus::resolve((float) $accountsReceivable->amount, $newPaidAmount)
            );

            $this->accountsReceivableRepository->applySettlement($accountsReceivable, $newPaidAmount, $newStatus);

            return $accountsReceivable->fresh();
        });
    }

    /**
     * Symmetric to settle() — called only by PaymentAllocationService::reverse()
     * to undo one settlement line and recompute status. Amount is clamped at
     * 0 rather than going negative; a reversal can never undo more than was
     * ever settled, so this is a defensive floor, not a real-world branch.
     */
    public function unsettle(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable
    {
        return DB::transaction(function () use ($accountsReceivable, $amount) {
            $newPaidAmount = max(0, $accountsReceivable->paid_amount - $amount);
            $newStatus = AccountsReceivableStatus::from(
                SettlementStatus::resolve((float) $accountsReceivable->amount, $newPaidAmount)
            );

            $this->accountsReceivableRepository->applySettlement($accountsReceivable, $newPaidAmount, $newStatus);

            return $accountsReceivable->fresh();
        });
    }

    /**
     * Shared by ReceiptEntryService and PaymentAllocationService — the one
     * place "can this much be applied to this receivable" is decided, so
     * both callers stay consistent instead of each re-deriving the math.
     */
    public function assertWithinOutstanding(AccountsReceivable $accountsReceivable, float $amount): void
    {
        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        $outstanding = (float) $accountsReceivable->amount - (float) $accountsReceivable->paid_amount;

        if ($amount > $outstanding) {
            throw new BusinessException("Amount ({$amount}) exceeds outstanding receivable ({$outstanding}) for {$accountsReceivable->reference_number}.");
        }
    }

    /**
     * Reduces the receivable's face amount — called only by
     * CreditNoteService::submit(). Distinct from settle()/unsettle(), which
     * only ever move paid_amount: a Credit Note changes what's actually
     * owed, not how much of it has been paid. Allowed to push paid_amount
     * above the new amount (an "overpaid" receivable, once a return is
     * credited after the customer already paid in full) — refunding that
     * difference is a future Customer Credit Balance capability, out of
     * scope here; see docs/CREDIT_NOTE_DESIGN.md §5/§7.
     */
    public function writeDown(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable
    {
        return DB::transaction(function () use ($accountsReceivable, $amount) {
            $newAmount = (float) $accountsReceivable->amount - $amount;
            $newCreditedAmount = (float) $accountsReceivable->credited_amount + $amount;
            $newStatus = AccountsReceivableStatus::from(
                SettlementStatus::resolve($newAmount, (float) $accountsReceivable->paid_amount)
            );

            $this->accountsReceivableRepository->applyWriteDown($accountsReceivable, $newAmount, $newCreditedAmount, $newStatus);

            return $accountsReceivable->fresh();
        });
    }

    /** Symmetric to writeDown() — called only by CreditNoteService::reverse(). */
    public function restoreWriteDown(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable
    {
        return DB::transaction(function () use ($accountsReceivable, $amount) {
            $newAmount = (float) $accountsReceivable->amount + $amount;
            $newCreditedAmount = max(0, (float) $accountsReceivable->credited_amount - $amount);
            $newStatus = AccountsReceivableStatus::from(
                SettlementStatus::resolve($newAmount, (float) $accountsReceivable->paid_amount)
            );

            $this->accountsReceivableRepository->applyWriteDown($accountsReceivable, $newAmount, $newCreditedAmount, $newStatus);

            return $accountsReceivable->fresh();
        });
    }

    /**
     * Increases the receivable's face amount — called only by
     * DebitNoteService::submit(). Symmetric partner to writeDown(), on the
     * same `amount` field. No ceiling to check (unlike writeDown(), which
     * CreditNoteService caps beforehand) — a Debit Note is always additive,
     * see docs/DEBIT_NOTE_DESIGN.md §0/§4.
     */
    public function writeUp(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable
    {
        return DB::transaction(function () use ($accountsReceivable, $amount) {
            $newAmount = (float) $accountsReceivable->amount + $amount;
            $newDebitedAmount = (float) $accountsReceivable->debited_amount + $amount;
            $newStatus = AccountsReceivableStatus::from(
                SettlementStatus::resolve($newAmount, (float) $accountsReceivable->paid_amount)
            );

            $this->accountsReceivableRepository->applyWriteUp($accountsReceivable, $newAmount, $newDebitedAmount, $newStatus);

            return $accountsReceivable->fresh();
        });
    }

    /** Symmetric to writeUp() — called only by DebitNoteService::reverse(). */
    public function restoreWriteUp(AccountsReceivable $accountsReceivable, float $amount): AccountsReceivable
    {
        return DB::transaction(function () use ($accountsReceivable, $amount) {
            $newAmount = max(0, (float) $accountsReceivable->amount - $amount);
            $newDebitedAmount = max(0, (float) $accountsReceivable->debited_amount - $amount);
            $newStatus = AccountsReceivableStatus::from(
                SettlementStatus::resolve($newAmount, (float) $accountsReceivable->paid_amount)
            );

            $this->accountsReceivableRepository->applyWriteUp($accountsReceivable, $newAmount, $newDebitedAmount, $newStatus);

            return $accountsReceivable->fresh();
        });
    }

    /**
     * The Credit Note equivalent of assertWithinOutstanding() — caps
     * against what's still creditable. `amount` is already net of every
     * prior credit (writeDown() subtracts from it directly), so the
     * current `amount` field *is* the remaining creditable balance —
     * `credited_amount` is a separate cumulative cache for display only
     * (mirrors receipt_entries.allocated_amount) and must not be
     * subtracted again here, or every prior credit gets double-counted.
     */
    public function assertWithinCreditableBalance(AccountsReceivable $accountsReceivable, float $amount): void
    {
        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        $creditable = (float) $accountsReceivable->amount;

        if ($amount > $creditable) {
            throw new BusinessException("Amount ({$amount}) exceeds the remaining creditable balance ({$creditable}) for {$accountsReceivable->reference_number}.");
        }
    }
}
