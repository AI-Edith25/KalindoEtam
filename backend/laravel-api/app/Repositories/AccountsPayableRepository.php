<?php

namespace App\Repositories;

use App\Enums\AccountsPayableStatus;
use App\Models\AccountsPayable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AccountsPayableRepository extends BaseRepository
{
    public function __construct(AccountsPayable $model)
    {
        parent::__construct($model);
    }

    public function applySettlement(AccountsPayable $accountsPayable, float $paidAmount, AccountsPayableStatus $status): void
    {
        $accountsPayable->update(['paid_amount' => $paidAmount, 'status' => $status]);
    }

    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(['supplier', 'purchaseOrder', 'goodsReceipt'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->latest('due_date')
            ->paginate($perPage);
    }

    public function outstandingSummary(): array
    {
        $notPaid = AccountsPayableStatus::PAID->value;

        return [
            'total_outstanding' => (float) $this->model->query()
                ->where('status', '!=', $notPaid)
                ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as outstanding')
                ->value('outstanding'),
            'count' => $this->model->query()->where('status', '!=', $notPaid)->count(),
        ];
    }
}
