<?php

namespace App\Repositories;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CustomerRepository extends BaseRepository
{
    public function __construct(Customer $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->model->query()->with('salesPerson');

        if ($search) {
            $query->where(fn ($q) => $q->where('customer_code', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%"));
        }

        // UUID primary key means an unordered scan has no relation to insertion order — without this,
        // a customer created after the page cap can silently never appear (see CustomerLookupOrderingTest).
        return $query->latest()->paginate($perPage);
    }

    /** Backs the Customers Export Excel — unpaginated, ordered by CusCode (the list itself has no fixed order to mirror). */
    public function exportQuery(?string $search, ?bool $isActive): Builder
    {
        $query = $this->model->query()->with(['salesPerson', 'termsOfPayment']);

        if ($search) {
            $query->where(fn ($q) => $q->where('customer_code', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%"));
        }

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->orderBy('customer_code');
    }
}
