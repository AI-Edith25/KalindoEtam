<?php

namespace App\Repositories;

use App\Models\ReceiptEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ReceiptEntryRepository extends BaseRepository
{
    protected const EAGER = ['customer', 'items.accountsReceivable.invoice', 'items.accountsReceivable.delivery'];

    public function __construct(ReceiptEntry $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('receipt_date')->paginate($perPage);
    }

    /** Same filtering shape as PaymentEntryRepository::search() — search matches document_number or the customer's name. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('receipt_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('receipt_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq) => $sq->where('customer_name', 'like', "%{$search}%"))
            ))
            ->latest('receipt_date')
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

    /** Same row-locking convention as NamingSeriesRepository::lockDefaultForType() — held for PaymentAllocationService's transaction. */
    public function lockForUpdate(string $id): ReceiptEntry
    {
        return $this->model->query()->where('id', $id)->lockForUpdate()->firstOrFail();
    }
}
