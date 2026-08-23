<?php

namespace App\Services;

use App\Enums\AccountsPayableStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountsPayable;
use App\Models\GoodsReceipt;
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
     * Called only by GoodsReceiptService::submit() — Accounts Payable is a
     * system-generated side effect, never created directly by a user.
     */
    public function createFromGoodsReceipt(GoodsReceipt $goodsReceipt): AccountsPayable
    {
        return DB::transaction(function () use ($goodsReceipt) {
            return $this->accountsPayableRepository->create([
                'supplier_id' => $goodsReceipt->supplier_id,
                'purchase_order_id' => $goodsReceipt->purchase_order_id,
                'goods_receipt_id' => $goodsReceipt->id,
                'reference_number' => $goodsReceipt->document_number,
                'amount' => $goodsReceipt->items->sum('amount'),
                'paid_amount' => 0,
                'due_date' => $goodsReceipt->due_date,
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
}
