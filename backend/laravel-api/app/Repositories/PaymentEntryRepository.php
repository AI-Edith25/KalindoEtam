<?php

namespace App\Repositories;

use App\Models\PaymentEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PaymentEntryRepository extends BaseRepository
{
    protected const EAGER = ['supplier', 'expenseAccount', 'cashAccount', 'branch', 'items.accountsPayable'];

    public function __construct(PaymentEntry $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('payment_date')->paginate($perPage);
    }

    /** Same filtering shape as GoodsReceiptRepository::search() — search matches document_number or the supplier's name. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('payment_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('payment_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$search}%"))
            ))
            // Only payment_type=supplier rows carry a meaningful allocated_amount — General
            // Expense payments never accumulate one (nothing to allocate against), so they're
            // excluded rather than showing up as nonsensically "always fully unallocated".
            ->when($filters['unallocated_only'] ?? null, fn ($query) => $query
                ->where('status', 'submitted')
                ->where('payment_type', 'supplier')
                ->whereColumn('allocated_amount', '<', 'total_amount'))
            ->latest('payment_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    public function recent(int $limit): Collection
    {
        return $this->model->query()->latest('created_at')->limit($limit)->get();
    }

    /** Same row-locking convention as ReceiptEntryRepository::lockForUpdate() — held for PaymentEntryAllocationService's transaction. */
    public function lockForUpdate(string $id): PaymentEntry
    {
        return $this->model->query()->where('id', $id)->lockForUpdate()->firstOrFail();
    }
}
