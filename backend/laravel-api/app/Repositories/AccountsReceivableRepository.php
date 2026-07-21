<?php

namespace App\Repositories;

use App\Enums\AccountsReceivableStatus;
use App\Models\AccountsReceivable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class AccountsReceivableRepository extends BaseRepository
{
    public function __construct(AccountsReceivable $model)
    {
        parent::__construct($model);
    }

    /**
     * Locks every targeted row for PaymentAllocationService::allocateBatch()'s
     * transaction, ordered by id so two concurrent batches touching an
     * overlapping set always acquire their locks in the same order —
     * prevents a deadlock instead of just detecting one.
     */
    public function lockManyForUpdate(array $ids): Collection
    {
        return $this->model->query()
            ->whereIn('id', array_unique($ids))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function applySettlement(AccountsReceivable $accountsReceivable, float $paidAmount, AccountsReceivableStatus $status): void
    {
        $accountsReceivable->update(['paid_amount' => $paidAmount, 'status' => $status]);
    }

    /** Used by CreditNoteService (via AccountsReceivableService::writeDown()/restoreWriteDown()) — reduces/restores the receivable's face amount, distinct from paid_amount. */
    public function applyWriteDown(AccountsReceivable $accountsReceivable, float $amount, float $creditedAmount, AccountsReceivableStatus $status): void
    {
        $accountsReceivable->update(['amount' => $amount, 'credited_amount' => $creditedAmount, 'status' => $status]);
    }

    /** Used by DebitNoteService (via AccountsReceivableService::writeUp()/restoreWriteUp()) — increases/restores the receivable's face amount, symmetric to applyWriteDown(). */
    public function applyWriteUp(AccountsReceivable $accountsReceivable, float $amount, float $debitedAmount, AccountsReceivableStatus $status): void
    {
        $accountsReceivable->update(['amount' => $amount, 'debited_amount' => $debitedAmount, 'status' => $status]);
    }

    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(['customer', 'invoice', 'salesOrder', 'delivery'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->latest('due_date')
            ->paginate($perPage);
    }

    public function outstandingSummary(): array
    {
        $notPaid = AccountsReceivableStatus::PAID->value;

        return [
            'total_outstanding' => (float) $this->model->query()
                ->where('status', '!=', $notPaid)
                ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as outstanding')
                ->value('outstanding'),
            'count' => $this->model->query()->where('status', '!=', $notPaid)->count(),
        ];
    }
}
