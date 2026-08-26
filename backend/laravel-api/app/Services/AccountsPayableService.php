<?php

namespace App\Services;

use App\Enums\AccountsPayableStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountsPayable;
use App\Models\PurchaseInvoice;
use App\Repositories\AccountsPayableRepository;
use App\Support\SettlementStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AccountsPayableService
{
    public function __construct(protected AccountsPayableRepository $accountsPayableRepository) {}

    public function list(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->accountsPayableRepository->search($filters, $perPage);
    }

    /**
     * Called only by PurchaseInvoiceService::submit() — Accounts Payable is a
     * system-generated side effect, never created directly by a user.
     * Amount is the Purchase Invoice's grand_total (authoritative, includes
     * tax), not re-derived from Goods Receipt lines. purchase_order_id/
     * goods_receipt_id are populated from the Invoice's anchor columns —
     * legacy fields kept for existing report queries, invoice_id is now the
     * authoritative link.
     */
    public function createFromInvoice(PurchaseInvoice $purchaseInvoice): AccountsPayable
    {
        return DB::transaction(function () use ($purchaseInvoice) {
            return $this->accountsPayableRepository->create([
                'supplier_id' => $purchaseInvoice->supplier_id,
                'invoice_id' => $purchaseInvoice->id,
                'purchase_order_id' => $purchaseInvoice->purchase_order_id,
                'goods_receipt_id' => $purchaseInvoice->goods_receipt_id,
                'reference_number' => $purchaseInvoice->document_number,
                'amount' => $purchaseInvoice->grand_total,
                'paid_amount' => 0,
                'due_date' => $purchaseInvoice->due_date,
                'status' => AccountsPayableStatus::UNPAID,
            ]);
        });
    }

    /**
     * Called only by PaymentEntryService::submit() — applies one
     * settlement line against this payable and recomputes its status.
     */
    public function settle(AccountsPayable $accountsPayable, float $amount): AccountsPayable
    {
        return DB::transaction(function () use ($accountsPayable, $amount) {
            $newPaidAmount = $accountsPayable->paid_amount + $amount;
            $newStatus = AccountsPayableStatus::from(
                SettlementStatus::resolve((float) $accountsPayable->amount, $newPaidAmount)
            );

            $this->accountsPayableRepository->applySettlement($accountsPayable, $newPaidAmount, $newStatus);

            return $accountsPayable->fresh();
        });
    }

    /**
     * Symmetric to settle() — called only by PaymentEntryAllocationService::reverse()
     * to undo one settlement line and recompute status. Amount is clamped at
     * 0 rather than going negative; a reversal can never undo more than was
     * ever settled, so this is a defensive floor, not a real-world branch.
     * Mirrors AccountsReceivableService::unsettle().
     */
    public function unsettle(AccountsPayable $accountsPayable, float $amount): AccountsPayable
    {
        return DB::transaction(function () use ($accountsPayable, $amount) {
            $newPaidAmount = max(0, $accountsPayable->paid_amount - $amount);
            $newStatus = AccountsPayableStatus::from(
                SettlementStatus::resolve((float) $accountsPayable->amount, $newPaidAmount)
            );

            $this->accountsPayableRepository->applySettlement($accountsPayable, $newPaidAmount, $newStatus);

            return $accountsPayable->fresh();
        });
    }

    /**
     * Shared by PaymentEntryService and PaymentEntryAllocationService — the
     * one place "can this much be applied to this payable" is decided, so
     * both callers stay consistent instead of each re-deriving the math.
     * Moved here from PaymentEntryService (was private, inline) now that
     * both services need it. Mirrors AccountsReceivableService::
     * assertWithinOutstanding().
     */
    public function assertWithinOutstanding(AccountsPayable $accountsPayable, float $amount): void
    {
        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        $outstanding = (float) $accountsPayable->amount - (float) $accountsPayable->paid_amount;

        if ($amount > $outstanding) {
            throw new BusinessException("Amount ({$amount}) exceeds outstanding payable ({$outstanding}) for {$accountsPayable->reference_number}.");
        }
    }

    /**
     * Reduces the payable's face amount — called only by
     * PurchaseReturnService::submit(). Distinct from settle()/unsettle(),
     * which only ever move paid_amount: a Purchase Return changes what's
     * actually owed, not how much of it has been paid. Mirrors
     * AccountsReceivableService::writeDown().
     */
    public function writeDown(AccountsPayable $accountsPayable, float $amount): AccountsPayable
    {
        return DB::transaction(function () use ($accountsPayable, $amount) {
            $newAmount = (float) $accountsPayable->amount - $amount;
            $newCreditedAmount = (float) $accountsPayable->credited_amount + $amount;
            $newStatus = AccountsPayableStatus::from(
                SettlementStatus::resolve($newAmount, (float) $accountsPayable->paid_amount)
            );

            $this->accountsPayableRepository->applyWriteDown($accountsPayable, $newAmount, $newCreditedAmount, $newStatus);

            return $accountsPayable->fresh();
        });
    }

    /** Symmetric to writeDown() — called only by PurchaseReturnService::reverse(). */
    public function restoreWriteDown(AccountsPayable $accountsPayable, float $amount): AccountsPayable
    {
        return DB::transaction(function () use ($accountsPayable, $amount) {
            $newAmount = (float) $accountsPayable->amount + $amount;
            $newCreditedAmount = max(0, (float) $accountsPayable->credited_amount - $amount);
            $newStatus = AccountsPayableStatus::from(
                SettlementStatus::resolve($newAmount, (float) $accountsPayable->paid_amount)
            );

            $this->accountsPayableRepository->applyWriteDown($accountsPayable, $newAmount, $newCreditedAmount, $newStatus);

            return $accountsPayable->fresh();
        });
    }

    /**
     * The Purchase Return equivalent of assertWithinOutstanding() — caps
     * against what's still returnable. `amount` is already net of every
     * prior return (writeDown() subtracts from it directly), so the
     * current `amount` field *is* the remaining returnable balance —
     * `credited_amount` is a separate cumulative cache for display only
     * and must not be subtracted again here. Mirrors
     * AccountsReceivableService::assertWithinCreditableBalance().
     */
    public function assertWithinCreditableBalance(AccountsPayable $accountsPayable, float $amount): void
    {
        if ($amount <= 0) {
            throw new BusinessException('Amount must be greater than zero.');
        }

        $creditable = (float) $accountsPayable->amount;

        if ($amount > $creditable) {
            throw new BusinessException("Amount ({$amount}) exceeds the remaining returnable balance ({$creditable}) for {$accountsPayable->reference_number}.");
        }
    }
}
