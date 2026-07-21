<?php

namespace App\Services;

use App\Enums\AccountsPayableStatus;
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
}
