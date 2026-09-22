<?php

namespace App\Repositories;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
}
